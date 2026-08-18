<?php

namespace WisdomIT\Concierge\Tools;

use App\Models\Egg;
use App\Services\Eggs\Sharing\EggImporterService;
use Illuminate\Support\Facades\Cache;
use WisdomIT\Concierge\Services\PlayerCount;
use Throwable;

/**
 * egg 를 찾아 들여오는 도구 (#105).
 *
 * 카탈로그 시작점(`egg_import`)은 관리자가 "이 패널에 없는 게임을 넣고 싶다"로 시작한다.
 * 그때까지 에이전트는 읽기만 할 수 있어서, 무엇이 없는지는 말해 주고 정작 들여오는 일은
 * "화면 툴바의 Import 버튼을 누르세요"로 돌려보냈다 — 알고도 못 하는 자리다(#48).
 *
 * 🔴 **URL 하나가 곧 남의 egg 정의를 받아 실행하라는 지시다.** egg 는 설치 스크립트와
 *    시작 명령을 품는다. 그래서 확인 카드가 **출처를 밝히고**, 공식 목록에서 오지 않은
 *    주소는 그렇다고 적는다 — 모델이 지어낸 주소를 관리자가 모르고 승인하는 일이 없도록.
 *
 * ⚠ 권한은 `import egg` 다. `create egg` 가 아니다 — Pelican 이 egg 에만 따로 둔 권한이고
 *   (Role::MODEL_SPECIFIC_PERMISSIONS), 화면의 Import 버튼도 그것으로 인가한다. egg 를
 *   새로 쓰는 것과 남의 egg 를 들여오는 것은 다른 일이다.
 *
 * ⚠ 파일 업로드는 다루지 않는다 — 대화에 파일을 올릴 자리가 없다. 공식 목록과 주소, 둘뿐이다.
 */
final class EggImportTools
{
    /** 한 번에 돌려줄 검색 결과 수. 목록 전체는 300개가 넘어 그대로 실으면 답이 아니라 덤프다. */
    private const SEARCH_LIMIT = 25;

    /** 패널이 인덱스를 담아 두는 캐시 키 (UpdateEggIndexCommand). */
    private const INDEX_KEY = 'eggs.index';

    /**
     * 공식 목록에서 찾는다.
     *
     * 검색어가 없으면 **목록을 쏟지 않고 분류와 개수만** 준다. 300개가 넘는 목록은 모델에게도
     * 사람에게도 읽을 것이 못 되고, 관리자는 어차피 게임 이름을 들고 온다.
     *
     * @param  array<string, mixed>  $input
     */
    public function listImportable(array $input): string
    {
        $index = $this->index();

        if ($index === []) {
            return 'The egg index is empty on this panel — it is fetched and cached by `php artisan p:egg:update-index`, '
                . 'which normally runs on a schedule. Until it has run, eggs can still be imported by URL with import_egg, '
                . 'but there is nothing to search. Say this plainly rather than reporting that no eggs exist.';
        }

        $imported = Egg::query()->pluck('name')->map(fn (string $n) => mb_strtolower($n))->all();
        $search = mb_strtolower(trim((string) ($input['search'] ?? '')));

        if ($search === '') {
            $lines = [];

            foreach ($index as $category => $eggs) {
                $lines[] = sprintf('- %s (%d)', $category, count($eggs));
            }

            return sprintf(
                "The official egg index holds %d eggs in %d categories:\n%s\n\n"
                . 'Ask which game they want and search for it by name — do not list these out.',
                array_sum(array_map('count', $index)),
                count($index),
                implode("\n", $lines),
            );
        }

        $hits = [];

        foreach ($index as $category => $eggs) {
            foreach ($eggs as $url => $name) {
                if (!str_contains(mb_strtolower($name), $search) && !str_contains(mb_strtolower($category), $search)) {
                    continue;
                }

                $hits[] = sprintf(
                    '- %s — %s%s%s%s',
                    $name,
                    $category,
                    // 🔴 이미 있는 것을 다시 들여오라고 제안하지 않도록 여기서 표시한다.
                    in_array(mb_strtolower($name), $imported, true) ? ' [already imported]' : '',
                    "\n  url: " . $url,
                    '',
                );
            }
        }

        if ($hits === []) {
            return sprintf(
                'Nothing in the official index matches "%s". The name in the index is the egg\'s own name, which is not '
                . 'always what the game is called — try a shorter or different word before concluding it is not there.',
                trim((string) $input['search']),
            );
        }

        $total = count($hits);
        $hits = array_slice($hits, 0, self::SEARCH_LIMIT);

        return sprintf(
            "%d match%s in the official index%s:\n%s\n\n"
            . 'To bring one in, call import_egg with its url. The administrator confirms before anything is fetched.',
            $total,
            $total === 1 ? '' : 'es',
            $total > self::SEARCH_LIMIT ? sprintf(' (showing the first %d — narrow the search for the rest)', self::SEARCH_LIMIT) : '',
            implode("\n", $hits),
        );
    }

    /**
     * 들여오기 전에 세우는 계획 — 확인 카드가 이 값을 그대로 보여준다.
     *
     * 🔴 **카드 앞에서 검사가 끝난다.** 주소가 말이 되는지, 공식 목록에서 온 것인지,
     *    이미 있는 egg 를 덮어쓰는 것인지를 여기서 판단한다. 승인 화면까지 갔다가
     *    실패하면 확인의 뜻이 없다.
     *
     * @param  array<string, mixed>  $input
     * @return array{url: string, name: string, category: ?string, from_index: bool, replaces: ?string}
     */
    public function plan(array $input): array
    {
        $url = trim((string) ($input['url'] ?? ''));

        if ($url === '') {
            throw new ToolInputException('An egg URL is required. Find one with list_importable_eggs, or ask the administrator for it.');
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // ⚠ http(s) 만 받는다. 이 주소는 **패널 서버가** 직접 열러 간다 — 다른 스킴을
        //   열어 두면 패널 안에서만 닿는 곳을 가리키게 만들 수 있다.
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ToolInputException('Only http and https URLs can be imported.');
        }

        $extension = mb_strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw new ToolInputException('An egg file is .json, .yaml or .yml — this URL points at something else.');
        }

        [$name, $category] = $this->lookup($url);

        return [
            'url' => $url,
            'name' => $name ?? trim((string) ($input['name'] ?? '')) ?: basename((string) parse_url($url, PHP_URL_PATH)),
            'category' => $category,
            'from_index' => $name !== null,
            // 같은 uuid 의 egg 가 이미 있으면 새로 만드는 것이 아니라 **덮어쓴다**
            // (EggImporterService::fromParsed). 그건 카드에 적혀야 하는 사실이다.
            'replaces' => $name !== null
                ? Egg::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('name')
                : null,
        ];
    }

    /**
     * 실제로 들여온다.
     *
     * ⚠ 패널의 Import 버튼은 InstallEgg 잡에 던지고 끝낸다 — 여러 개를 한 번에 받기 때문이다.
     *   여기서는 **그 자리에서** 부른다. 하나뿐이고, fromUrl 은 timeout 5초·connect 1초로
     *   묶여 있으며, 무엇보다 그래야 에이전트가 "들여왔어요"를 실제로 확인하고 말할 수 있다.
     *   잡에 던지면 실패가 로그로만 남아(InstallEgg 는 예외를 삼킨다) 대화는 성공했다고 말한다.
     *
     * @param  array<string, mixed>  $input
     */
    public function import(array $input): string
    {
        $plan = $this->plan($input);

        try {
            $egg = app(EggImporterService::class)->fromUrl($plan['url']);
        } catch (Throwable $e) {
            // 주소는 그대로 돌려준다 — 오타 하나로 실패하는 일이 흔하고, 무엇을 열려다
            // 실패했는지 보여야 관리자가 다음 수를 둘 수 있다.
            return sprintf(
                "Could not import from %s — %s\nNothing was changed on this panel.",
                $plan['url'],
                $e->getMessage(),
            );
        }

        return sprintf(
            '%s the egg "%s" (id %d)%s. It can be used for new servers right away, and appears under Eggs on the admin side. '
            . 'If it should also be offered by the assistant, it needs a catalogue entry — create_catalog_game does that.%s',
            $plan['replaces'] !== null ? 'Updated' : 'Imported',
            $egg->name,
            $egg->id,
            filled($egg->author) ? sprintf(' by %s', $egg->author) : '',
            $this->playerCountNote(),
        );
    }

    /**
     * 접속자 수 규약도 없다는 사실을 **여기서** 말한다 (#112).
     *
     * egg 를 막 들여온 순간은 관리자가 그 게임을 생각하고 있는 유일한 때다. 그때 말하지
     * 않으면 영영 말할 기회가 없고, 접속자 수가 없다는 것은 나중에 빈 위젯으로만 드러난다 —
     * 그건 "서버에 아무도 없다"와 구분되지 않는다. 카탈로그 안내와 같은 자리다.
     *
     * 🔴 **Player Counter 가 있을 때만.** 없는 플러그인의 기능을 권하는 것은 안내가 아니라
     *    막다른 길이고(#48), 그 말을 읽은 관리자는 없는 화면을 찾아다니게 된다.
     */
    private function playerCountNote(): string
    {
        return PlayerCount::available()
            ? ' It also has no player-count recipe yet, so servers on it will show nobody online — set_egg_game_query links one.'
            : '';
    }

    /**
     * 이 주소가 공식 목록에 있는가 — 있으면 [이름, 분류], 없으면 [null, null].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function lookup(string $url): array
    {
        foreach ($this->index() as $category => $eggs) {
            if (isset($eggs[$url])) {
                return [(string) $eggs[$url], (string) $category];
            }
        }

        return [null, null];
    }

    /**
     * 패널이 캐시해 둔 공식 목록: 분류 → (다운로드 주소 → egg 이름).
     *
     * ⚠ 우리가 채우지 않는다. 패널의 `p:egg:update-index` 가 CDN 에서 받아 넣는 것을 읽기만
     *   한다 — 목록의 출처가 갈리면 카드에 적는 "공식 목록에서 왔다"가 거짓이 된다.
     *
     * @return array<string, array<string, string>>
     */
    private function index(): array
    {
        $index = Cache::get(self::INDEX_KEY);

        return is_array($index) ? $index : [];
    }
}
