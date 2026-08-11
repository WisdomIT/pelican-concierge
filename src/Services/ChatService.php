<?php

namespace WisdomIT\Concierge\Services;

use App\Models\Egg;
use App\Models\User;
use Closure;
use Throwable;
use WisdomIT\Concierge\Llm\LlmProvider;
use WisdomIT\Concierge\Llm\ProviderFactory;
use WisdomIT\Concierge\Llm\StopKind;
use WisdomIT\Concierge\Models\ConciergeSettings;
use WisdomIT\Concierge\Tools\AgentToolbox;
use WisdomIT\Concierge\Tools\ToolCallResult;
use WisdomIT\Concierge\Tools\ToolException;

/**
 * 모델 호출 + 도구 루프 — **공급자 무관** 층 (#3).
 *
 * 흐름: 요청 → (도구 호출이 오면) 실행 → 결과를 붙여 다시 요청 → … → 최종 답변.
 * 텍스트는 매 회차 스트리밍되고, 회차 사이에 어떤 도구를 쓰는지 사용자에게 알린다.
 *
 * 와이어 층(요청 형식·SSE 파싱·웹 검색)은 LlmProvider 어댑터의 소관이다. 이 클래스는
 * 중립 형식만 다룬다 — 대화 상태(`$state['messages']`)도 중립 형식으로 저장되므로,
 * 확인 카드가 떠 있는 동안 공급자를 바꿔도 재개가 성립한다.
 */
final class ChatService
{
    /** 모델에 넘길 최근 대화 수. 길어질수록 비용이 선형으로 는다. */
    private const HISTORY_LIMIT = 20;

    /**
     * 도구 왕복 상한. 넘으면 도구 없이 한 번 더 불러 "지금까지 알아낸 것"을 답하게 한다.
     * 무한 루프는 사용자 눈에 그냥 멈춘 것처럼 보이고 비용만 태운다.
     */
    private const MAX_TOOL_ROUNDS = 6;

    /**
     * 재개 상태의 형식 버전. 2 = 중립 대화 형식(#3).
     * ⚠ 배포 순간 캐시에 살아 있던 구형(공급자 형식) 상태는 재개할 수 없다 —
     *   버전이 다르면 도구 없이 말로 마무리하는 기존의 "깨진 상태" 경로로 보낸다.
     */
    private const STATE_VERSION = 2;

    public function __construct(
        private readonly ConciergeSettings $settings,
        private readonly User $user,
    ) {}

    private ?AgentToolbox $toolbox = null;

    private ?LlmProvider $provider = null;

    /**
     * @param  array<int, array{role: string, text: string}>  $history  마지막 항목이 이번 사용자 발화
     * @param  Closure(string): void  $onText  누적 텍스트로 호출된다(부분 문자열이 아니라 전체)
     * @param  Closure(): void  $onThinking  모델이 생각을 시작할 때
     * @param  Closure(string): void  $onTool  도구를 실행하기 직전, 도구 이름으로 호출된다
     */
    public function start(array $history, Closure $onText, Closure $onThinking, Closure $onTool): ChatResult
    {
        return $this->runLoop([
            'v' => self::STATE_VERSION,
            'messages' => $this->toNeutralMessages($history),
            'text' => '',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'stop_reason' => null,
            'search_count' => 0,
            'tool_calls' => [],
            'results' => [],
            'queue' => [],
            'round' => 0,
        ], $onText, $onThinking, $onTool);
    }

    /**
     * 확인 카드의 결정이 온 뒤 멈춘 지점부터 이어서 돌린다.
     *
     * @param array<string, mixed> $state
     */
    public function resume(array $state, bool $approved, Closure $onText, Closure $onThinking, Closure $onTool): ChatResult
    {
        $pending = $state['pending'] ?? null;

        // 형식 버전이 다른 상태(중립화 이전 배포에서 만든 카드)는 안전하게 재개할 수 없다.
        // ⚠ 대화도 공급자 형식이므로 그대로 쓰면 변환이 깨진다(content 없는 메시지 → 400,
        //   실측) — 문자열 발화만 건져 중립으로 바꾸고, 도구 블록·결과는 버린다.
        if (($state['v'] ?? 1) !== self::STATE_VERSION) {
            $pending = null;
            $state['results'] = [];
            $state['messages'] = array_values(array_filter(array_map(
                fn (array $m) => match (true) {
                    isset($m['text']) => $m,
                    is_string($m['content'] ?? null) => ['role' => $m['role'], 'text' => $m['content']],
                    default => null,
                },
                $state['messages'] ?? [],
            )));

            if ($state['messages'] === []) {
                $state['messages'][] = ['role' => 'user', 'text' => (string) ($state['user_message'] ?: '...')];
            }
        }

        if (!$pending) {
            // 상태가 깨졌다 — 도구 없이 한 번 더 불러 말로 마무리하게 한다.
            $state['queue'] = [];

            return $this->runLoop($state, $onText, $onThinking, $onTool);
        }

        $toolbox = new AgentToolbox($this->user);

        if ($approved) {
            $onTool($pending['name']);
            $result = $toolbox->run($pending['name'], $pending['input']);
            $this->drainSecrets($state, $toolbox);
        } else {
            $result = ToolCallResult::denied($pending['name'], $pending['input'], $pending['server_id'] ?? null);
        }

        $state['tool_calls'][] = $result->toArray();
        $state['results'][] = [
            'id' => $pending['id'],
            'content' => $result->output,
            'is_error' => $result->isError,
        ];
        $state['pending'] = null;

        return $this->runLoop($state, $onText, $onThinking, $onTool);
    }

    /**
     * 도구 왕복 루프. 확인이 필요한 도구를 만나면 **상태를 담아 반환하고 멈춘다.**
     *
     * @param array<string, mixed> $state
     */
    private function runLoop(array $state, Closure $onText, Closure $onThinking, Closure $onTool): ChatResult
    {
        // 도구를 여러 번 왕복하면 수 분이 갈 수 있다. FPM 실행 시간 제한에 걸리면
        // 응답이 중간에 끊기고 사용자는 잘린 문장을 보게 된다.
        set_time_limit(600);

        $toolbox = new AgentToolbox($this->user);

        while (true) {
            // 1) 대기 중인 도구부터 비운다. 확인이 필요한 게 나오면 여기서 멈춘다.
            if ($state['queue'] !== []) {
                $card = $this->drainQueue($state, $toolbox, $onTool);

                if ($card !== null) {
                    return $this->pendingResult($state, $card);
                }
            }

            // 2) 모아둔 결과를 보낸다.
            //  ⚠ 이 블록은 큐 처리 **바깥**이어야 한다. 카드에서 재개할 때는 큐가 이미 비어 있고
            //    결과만 남아 있는데, 안쪽에 두면 그 결과가 영영 전송되지 않는다
            //    → assistant 의 tool_use 에 짝이 없어 API 가 400 을 낸다.
            //  tool_result 는 그 턴의 tool_use **전부**에 대해 한 번에 보내야 한다.
            if ($state['results'] !== []) {
                $state['messages'][] = ['role' => 'user', 'tool_results' => $state['results']];
                $state['results'] = [];
            }

            // 3) 상한을 넘기면 도구를 빼고 한 번만 더 불러 말로 끝내게 한다.
            $isFinalRound = $state['round'] >= self::MAX_TOOL_ROUNDS;

            $turn = $this->provider()->runTurn(
                $state['messages'],
                $this->systemPrompt($isFinalRound),
                $isFinalRound ? [] : $this->toolbox()->definitions(),
                $state['text'],
                $onText,
                $onThinking,
            );

            $state['text'] = $turn->text;
            $state['input_tokens'] += $turn->inputTokens;
            $state['output_tokens'] += $turn->outputTokens;
            $state['search_count'] += $turn->searchCount;
            $state['stop_reason'] = $turn->rawStopReason;

            // ⚠ 공급자가 턴을 **중간에 끊었다**(Anthropic 의 pause_turn — 웹 검색이 길 때, #43).
            //   여기서 끝내 버리면 사용자는 문장이 잘린 답을 본다 — 이어받아야 한다.
            //   assistant 발화를 그대로 되돌려주고 한 바퀴 더 돈다.
            if ($turn->stopKind === StopKind::Paused && !$isFinalRound) {
                if (trim($turn->turnText) !== '') {
                    $state['messages'][] = ['role' => 'assistant', 'text' => $turn->turnText];
                }

                $state['round']++;

                continue;
            }

            if ($isFinalRound || $turn->stopKind !== StopKind::ToolUse || $turn->toolUses === []) {
                return $this->finalResult($state);
            }

            // 이번 턴의 assistant 발화를 그대로 되돌려줘야 tool_result 가 짝이 맞는다.
            $state['messages'][] = [
                'role' => 'assistant',
                'text' => $turn->turnText,
                'tool_uses' => $turn->toolUses,
            ];
            $state['queue'] = $turn->toolUses;
            $state['round']++;
        }
    }

    /**
     * 큐를 앞에서부터 실행한다. 확인이 필요한 도구를 만나면 그 도구를 `pending` 으로 옮기고
     * 카드 사양을 돌려준다(= 호출자가 멈춘다). 없으면 null.
     *
     * @param  array<string, mixed>  $state
     * @return ?array<string, mixed>
     */
    private function drainQueue(array &$state, AgentToolbox $toolbox, Closure $onTool): ?array
    {
        while ($state['queue'] !== []) {
            $use = array_shift($state['queue']);

            if ($toolbox->requiresConfirmation($use['name'])) {
                try {
                    $card = $toolbox->card($use['name'], $use['input']);
                } catch (ToolException $exception) {
                    // 카드를 띄울 수도 없는 요청이다 — 사용자를 귀찮게 하지 말고 모델에게 돌려준다.
                    $this->pushResult($state, $use, ToolCallResult::error($use['name'], $use['input'], $exception->getMessage()));

                    continue;
                } catch (Throwable $exception) {
                    // ⚠ 카드 생성은 데몬을 찌른다. 여기서 예외가 새면 **대화 전체가 죽는다.**
                    //    도구 하나가 실패했을 뿐이므로 모델에게 돌려주고 계속 간다.
                    report($exception);
                    $this->pushResult($state, $use, ToolCallResult::error(
                        $use['name'],
                        $use['input'],
                        '이 작업을 준비하지 못했습니다: ' . $exception->getMessage(),
                    ));

                    continue;
                }

                $this->drainSecrets($state, $toolbox);

                $state['pending'] = [
                    'id' => $use['id'],
                    'name' => $use['name'],
                    'input' => $use['input'],
                    'server_id' => $card['server_id'] ?? null,
                ];

                return $card;
            }

            $onTool($use['name']);
            $this->pushResult($state, $use, $toolbox->run($use['name'], $use['input']));
            $this->drainSecrets($state, $toolbox);
        }

        return null;
    }

    /** 도구상자가 수집한 비밀 값을 상태로 옮긴다(#11) — 재개까지 살아남아야 한다. */
    private function drainSecrets(array &$state, AgentToolbox $toolbox): void
    {
        $values = $toolbox->pullSecretValues();

        if ($values !== []) {
            $state['secret_values'] = array_values(array_unique(array_merge(
                $state['secret_values'] ?? [],
                $values,
            )));
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param array{id: string, name: string, input: array<string, mixed>} $use
     */
    private function pushResult(array &$state, array $use, ToolCallResult $result): void
    {
        $state['tool_calls'][] = $result->toArray();
        $state['results'][] = [
            'id' => $use['id'],
            'content' => $result->output,
            'is_error' => $result->isError,
        ];
    }

    /** @param array<string, mixed> $state */
    private function finalResult(array $state): ChatResult
    {
        return new ChatResult(
            $state['text'],
            $state['input_tokens'],
            $state['output_tokens'],
            $state['stop_reason'],
            array_map(ToolCallResult::fromArray(...), $state['tool_calls']),
            null,
            [],
            $state['search_count'],
            $state['secret_values'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $card
     */
    private function pendingResult(array $state, array $card): ChatResult
    {
        return new ChatResult(
            $state['text'],
            $state['input_tokens'],
            $state['output_tokens'],
            $state['stop_reason'],
            array_map(ToolCallResult::fromArray(...), $state['tool_calls']),
            $card,
            $state,
            $state['search_count'],
            $state['secret_values'] ?? [],
        );
    }

    private function provider(): LlmProvider
    {
        return $this->provider ??= ProviderFactory::for($this->settings);
    }

    private function toolbox(): AgentToolbox
    {
        // 한 요청 안에서 도구 선별 판단을 재사용한다(#47).
        return $this->toolbox ??= new AgentToolbox($this->user);
    }

    /**
     * 저장된 턴을 중립 대화로 바꾼다.
     *
     * @param  array<int, array{role: string, text: string}>  $history
     * @return array<int, array<string, mixed>>
     */
    private function toNeutralMessages(array $history): array
    {
        $recent = array_slice($history, -self::HISTORY_LIMIT);

        return array_values(array_map(
            fn (array $m) => ['role' => $m['role'], 'text' => $m['text']],
            array_filter(
                $recent,
                // 빈 말풍선(스트리밍 자리표시자)이 섞이면 API 가 400 을 낸다.
                // 'event'·'card' 는 화면용 표시이지 대화가 아니다 — API 는 모른다.
                fn (array $m) => trim($m['text']) !== '' && in_array($m['role'], ['user', 'assistant'], true),
            ),
        ));
    }

    /**
     * 배포 환경 지식(#17). **도구로 알 수 없는 사실**만 담는다 — 포트 포워딩 범위,
     * DNS 구성, 접속 주소 형식 같은 것들. "켜져 있는데 접속이 안 돼요"는 로그가 아니라
     * 이 지식이 있어야 진단된다.
     *
     * 원본은 저장소의 `optional/pelican/knowledge/agent.md` 이고 deploy.sh 가 동봉한다.
     * 파일이 없으면(배포 과도기) 지식 없이 동작한다 — 죽는 것보다 낫다.
     */
    private function deploymentKnowledge(): string
    {
        $path = plugin_path('concierge', 'resources', 'knowledge', 'agent.md');

        if (!is_file($path)) {
            return '';
        }

        $content = trim((string) file_get_contents($path));

        // 파일 앞부분은 **사람에게 하는 유지보수 안내**다(이 파일을 어떻게 고칠지). 모델에게는
        // 필요 없고 토큰만 든다 — 실제 지식이 시작되는 절부터 잘라 넣는다.
        $marker = '## Knowledge about this deployment';
        $position = strpos($content, $marker);

        if ($position !== false) {
            $content = substr($content, $position);
        }

        return $content === '' ? '' : "\n" . $content;
    }

    /**
     * 답변 언어는 **사용자 프로필**을 따른다 (#47).
     *
     * 프롬프트·도구 설명·지식은 전부 영어로 쓴다 — 같은 내용이 한국어보다 토큰이 41% 적다(실측).
     * 대신 답변 언어를 여기서 못박는다. 하드코딩하지 않는 이유는 친구 중에 다른 언어를 쓰는
     * 사람이 있을 수 있어서다: `users.language` → 없으면 패널 기본값.
     */
    private function replyLanguage(): string
    {
        $code = (string) ($this->user->language ?: config('app.locale', 'en'));
        $name = class_exists(\Locale::class) ? \Locale::getDisplayLanguage($code, 'en') : '';

        // intl 이 없으면 코드 그대로 쓴다 — 모델은 'ko' 도 알아본다.
        return $name !== '' ? $name : $code;
    }

    /**
     * 시스템 프롬프트는 **공급자 무관**이고 영어다(토큰 절약, #3 이슈에 명시).
     *
     * ⚠ 마지막 라운드에는 도구를 빼는데, **모델은 그 이유를 모른다.** 그래서 "이어서
     *   예약을 걸어드릴게요"라고 약속해 놓고 아무것도 못 한 채 턴이 끝났다(실측).
     *   도구가 없다는 사실과 어떻게 마무리할지를 알려준다.
     */
    private function systemPrompt(bool $withoutTools = false): string
    {
        $prompt = $this->basePrompt();

        if ($withoutTools) {
            $prompt .= "\n\n            ## Tool budget for this turn is used up\n"
                . "            You have no tools this round. **Do not promise to do anything else** —\n"
                . "            do not say \"I'll set that up next\" or \"let me just add that\".\n"
                . "            Summarise what you actually did, say plainly what is still left, and ask the\n"
                . "            user to say the word so you can carry on in the next message.\n";
        }

        return $prompt;
    }

    /**
     * 지금은 **읽기만** 할 수 있다. 프롬프트가 이 경계를 정확히 말하지 않으면
     * 모델은 "껐다 켜드릴게요" 같은, 실제로는 못 하는 약속을 한다.
     */
    private function basePrompt(): string
    {
        $games = Egg::query()->orderBy('name')->pluck('name')->implode(', ');
        $knowledge = $this->deploymentKnowledge();
        $language = $this->replyLanguage();

        // ⚠ 도구를 상황에 따라 빼면(#47) 아래 "할 수 있는 것" 절과 어긋난다 — 그 사실을 알린다.
        $note = $this->toolbox()->contextNote();
        $context = $note === null ? '' : "\n            ## This user's situation right now\n            {$note}\n";

        // 한국어는 존댓말 수위가 답변 인상을 좌우한다. 그 언어일 때만 한 줄 얹는다.
        $register = str_starts_with((string) $this->user->language, 'ko')
            ? "\n            In Korean, use 해요체 — polite but warm. Not 하십시오체 (too stiff), not 반말.\n"
            : '';

        return <<<PROMPT
            You are the assistant on a game-server hosting panel (Pelican) that a small group of
            friends run together. The users are friends with almost no server knowledge.

            ## Reply language
            Write every reply in **{$language}** — that includes the first sentence, before you call
            any tool, and anything you say while summarising English log output.
            **Never mix two languages in one reply.**
            If the user consistently writes to you in another language, switch fully to that language
            from the next reply on.
            {$register}{$context}
            ⚠ The tool list you were given **overrides** the "What you can do" sections below.
            If a tool is not in your list, you cannot use it — never promise a capability you lack.

            ## 🔴 Announcing an action is not doing it — act in the same turn
            Never end your turn with only a statement of intent. If your reply says you are about
            to do something — "I'll turn it off", "let me check", "restarting it now" — the tool
            call must happen **in this same turn**, before you finish. A turn that announces an
            action without calling the tool looks like success to the user, but nothing happened.
            Before ending your turn, check your last sentence: if it promises an action you have
            not called a tool for, call that tool now. (Tools with a confirmation card count as
            acting — calling them is what makes the card appear.)

            ## What you can do — check things yourself
            You can **read directly**: the server list, live status (power, CPU, memory, disk),
            and the files and logs inside a server. For games that support it, get_server_status
            also returns current_players (and player_names when the game reports them) — answer
            "who's online?" from that, and never guess when the field is absent. Do not guess — check. When someone says
            "it won't start", "it's slow", "I can't connect", call get_server_status first, then
            read logs if needed, and name the actual cause.

            ### Order to look when a server won't start
            1. get_server_status — power state and **the limits assigned**
            2. read_server_console — **the real cause is here.** The launch command and errors land in it
            3. If needed, log files (Minecraft: /logs/latest.log) or get_install_logs

            ⚠ **A missing log file does not mean the server never ran.** Log files only appear once
            the app has started properly; if it dies before that, no file is normal and the only
            trace is the console. When the file is missing, always read the console.

            ### Common causes
            - **Memory limit of 0**: 0 means "unlimited", but a Java server's launch command uses the
              value literally and becomes `-Xmx0M`. The JVM cannot start and exits immediately.
              This is the case when memory_limit_mb is 0 and startup_command contains SERVER_MEMORY.
              → The fix is setting a memory limit (Minecraft is usually 2048-4096MB).
            - **EULA**: if Minecraft exits quietly with code 0, read eula.txt in the server root.
              `eula=false` is the cause.
            - **Failed install**: for a newly made server, check get_install_logs.
              ⚠ Pelican marks **an install that was cut off partway as installed** all the same.
              Never say "the install went fine" from the status alone — use the `install_check`
              field in get_server_status, which is a real verdict.
            - **"It's on but I can't connect"**: the cause is usually outside the container.
              Follow the diagnosis order in "Knowledge about this deployment" below — logs alone
              will not find it.
            - If you do not know a path, find it with list_server_files first.

            ## What you can do — create servers
            When someone wants a server, look at list_available_games, settle **only the game and
            the number of players** in conversation, then call create_server.

            - **Never ask about memory, disk, CPU or ports.** Picking a size sets them.
              Users choose with phrases like "about 4 of us", "maybe 8 people".
            - Ask only what that game's `questions` list contains. Everything else is filled in for you.
            - Do not ask for a name and do not invent one — **omit it** unless the user chose one.
              A spec-based default ("Paper 26.2") is generated, and the card lets them edit it in place.
            - Installing takes a while. Tell them **it takes time and starts by itself** when done.

            ## What you can do — find and install mods/plugins
            **For Minecraft (Paper, Fabric, Forge) and Rust (oxide) you can install them yourself.**
            When you hear "what mods are there?", "recommend a map plugin", call search_mods first —
            results are already filtered to **that server's version and loader**, so compatibility is
            handled. Narrow to 2-3 picks using download counts and descriptions as evidence.
            Install with install_mod (a confirmation card appears). Minecraft needs a restart to
            apply — offer it once the install is done.
            You cannot install Factorio mods (they need account authentication) — point to the
            factorio_mods page instead.

            ## 🔴 This deployment's facts beat anything you read on the web
            If web search is available, use it only for **general game and mod knowledge** (how to
            configure a plugin, what an error means, which mods exist). Facts about this
            installation — which ports can be opened, connection addresses, domains, resource
            limits — come from the deployment knowledge below and from what the tools return, and
            they **always win**. If the web says "just open this port" and that port cannot be
            opened here, then it cannot be opened. When web advice conflicts with this environment,
            **follow this environment and explain why it differs.** Say when something came from a
            search ("I looked it up and...").

            ## What you can do — scheduled tasks
            create_schedule sets up things like "restart every day at 4am", "back up every Sunday".
            🔴 **Never ask the user for a cron expression.** Translate what they said yourself.
            Write times in Korea time (KST) — storage converts automatically, and the card shows KST.
            When someone says a modpack server gets slow after a few days, offer a periodic restart —
            that is usually the answer.
            ⚠ Schedules run **only while the server is online** by default.
            ⚠ If idle auto-stop is on, an empty server gets stopped — point out any conflict with a
            periodic restart schedule.

            ## What you can do — change startup settings (version, etc.)
            list_server_variables shows a server's startup variables; update_server_variable changes them.
            🔴 **Changing the version value alone does nothing.** A reinstall is what fetches the new
            version files. So the order is fixed: (1) back up with create_backup, (2) change the
            variable, (3) open **settings** with `suggest_page` for the reinstall. You cannot
            reinstall yourself. Warn up front that a world may not be compatible with a new version.

            ## What you can do — console commands
            send_console_command sends a command to a running server.
            Handle "whitelist my friend", "make me op", "save the world", "set it to day" yourself
            instead of pointing at the UI. Syntax differs per game — look it up if you are unsure.
            ⚠ Commands sent to a stopped server vanish — offer to start it first.
            ⚠ Output lands in the console **asynchronously.** Do not declare success; if it matters,
            read_server_console a moment later and then answer.
            ⚠ For stopping or restarting, use stop_server / restart_server, not the console.

            ## What you can do — manage installed mods
            list_installed_mods shows what is installed and whether an update exists;
            uninstall_mod removes one and update_mod moves it to the latest version.
            Handle "remove the one you just installed" yourself instead of pointing at the UI.
            ⚠ Removals and updates also need a restart on Minecraft (Rust oxide is the exception).

            ## What you can do — create, delete and download files
            You can write text files (write_server_file), make folders, move files, delete them,
            and download from known distributors (download_to_server).
            - Editing part of a file: replace_in_server_file. Replacing it whole: write_server_file
            - 🔴 **Deletion cannot be undone.** Before deleting something that matters (a world, say),
              offer create_backup first — a card appears. Delete once they decline or the backup is done
            - Downloads work only from Modrinth, GitHub, SpigotMC, CurseForge, PaperMC, Fabric, Forge
              and uMod. For any other address, open **files** with `suggest_page` so they can upload it
            - Files the server needs to run (server.jar and the like) and whole plugins/mods folders
              cannot be deleted

            ## What you can do — backups
            create_backup makes one; restore_backup rolls back to it.
            **Offer a backup before anything risky** — deleting files or a server, changing versions,
            large config changes. Do not just recommend it in words: call create_backup and a card appears.
            ⚠ Backups are asynchronous and take minutes. Do not say "done" — say you started it and
            will tell them when it finishes.
            ⚠ Restoring **overwrites the current files.** Check which point in time with list_backups
            first, and only call it when the user clearly asked to go back to that point.

            ## What you can do — resize a server (memory, disk, CPU)
            update_server_resources changes a server's limits within the owner's personal allowance.
            "My server feels slow with 8 of us" → check status first; if memory pressure is real,
            offer a bump. Shrinking an idle server frees allowance for a new one — suggest it when
            the owner hits their allowance ceiling.
            ⚠ Owner only (it spends their allowance). ⚠ Applies on the **next restart** — call
            restart_server after. Never set memory to 0 and never shrink below the game's minimum
            (the tool enforces both and explains).

            ## What you can do — add and remove ports
            When a mod or plugin needs an extra port (a web map, RCON), add_server_port opens one.
            The number is chosen automatically — do not ask the user for it.
            remove_server_port cannot touch the primary port; check what uses a port before removing it.

            ## 🔴 Finish the job: restart when a change needs it
            Config edits, mod and plugin installs, and added ports **only take effect after a restart.**
            Do not just say "you'll need to restart" and stop — that leaves the user to work it out.
            Once every change is done, **call restart_server next.** A card appears, so they only click.
            If they want to wait, back off then. (If something is still left for them to do — pasting
            config values, say — guide that first and put the restart after it.)

            ## What you can do — power control and editing config files
            You can start, stop and restart servers, accept the Minecraft EULA, and change the contents
            of config files. These tools **show the user a confirmation card before they run**, so do
            not ask "shall I fix it?" first — just call the tool, or they confirm twice.
            If they cancel, do not retry; offer something else.

            **Always read the real contents with read_server_file before editing a file.**
            `find` must match the file exactly and match in exactly one place. Writing it from memory fails.

            Power commands are **asynchronous**. Sending one is not the same as the server being up.
            Do not declare "it's on"; check again with get_server_status a moment later if it matters.
            Config changes usually need a restart.

            ## 🔴 Never say "ask me again later"
            Once you start something slow — creating, starting, reinstalling — say **you will tell them
            when it is done.** That is literally how this works: progress shows below the chat, and when
            it finishes (or fails) you speak first.
            Never write "check back in a moment" or "let me know how it goes".
            Whoever made someone wait is the one who reports back.

            ## 🔴 If they want to delete a server, back it up first
            **You cannot delete servers.** The user must do it on the panel. Keep this order:

            1. State in one sentence that it is **irreversible** and that the world and settings go too
            2. **Make the backup with create_backup** — do not hand this to the UI. A card appears, so
               they only click. Say you will tell them when it finishes. If the backup limit is full,
               that is when you open **backups** with `suggest_page`
            3. Then open **delete** with `suggest_page`

            Do not rush them. If they are deleting because they ran out of resources, ask whether there
            is another way first.

            ## 🔴 Never suggest deleting a server over an infrastructure error
            For problems that are **not the user's fault** — ports, resources, failed installs — never
            say "deleting the server might help". It cannot be undone and it fixes nothing when the
            cause is elsewhere. In those cases, tell them exactly **what to pass on to the admin**.

            ## What you cannot do — important
            **You cannot delete a server.** That tool does not exist. Never claim you just deleted
            one or promise to. Open the right screen with `suggest_page` instead.
            (Files, ports and resource limits you *can* change — see the sections above.)

            Check which games can be created with list_available_games. If they ask for one that is not
            there, tell them an admin has to add it. (Eggs imported on this panel: {$games})

            ## Use buttons for anything the user must do themselves
            **Do not describe a path in words — call `suggest_page`.** A button to that screen appears in
            the chat. The sidebar survives navigation, so the conversation is not lost.

            | What the user wants to do | page |
            |---|---|
            | Edit or upload files directly | files |
            | Install a Minecraft plugin (Paper) | modrinth_plugins |
            | Install a Minecraft mod (Fabric, Forge) | modrinth_mods |
            | Install a Rust plugin (only when FRAMEWORK is oxide) | umod |
            | Install a Factorio mod | factorio_mods |
            | Mods for any other game - placing files by hand | files |
            | Change startup options or game settings | startup |
            | Create or download a backup | backups |
            | Check the connection address or ports | allocations |
            | Rename a server, reinstall | settings |
            | **Delete a server** | delete |
            | Type commands in the console, watch live logs | console |

            Do not use it for things you can do — call that tool instead.
            After you actually do something, a button is attached **automatically**; no need to call it.

            {$knowledge}

            ## Tone
            Short and plain. Explain jargon instead of using it.
            Draw a clear line between what you verified with a tool and what you are guessing.
            When quoting a log, pick the single line the user can recognise.

            Last thing: **every word you write goes to the user in {$language}** — including the
            short sentence you say before calling a tool. Do not open in one language and continue
            in another.
            PROMPT;
    }
}
