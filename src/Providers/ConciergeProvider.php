<?php

namespace WisdomIT\Concierge\Providers;

use App\Events\Server\Installed;
use App\Enums\ConsoleWidgetPosition;
use App\Filament\Server\Pages\Console;
use App\Models\Role;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;
use Illuminate\Support\Facades\Schedule;
use WisdomIT\Concierge\Catalog\GameCatalog;
use WisdomIT\Concierge\Console\CheckIdleServers;
use WisdomIT\Concierge\Filament\Server\Widgets\PlayerCountWidget;
use WisdomIT\Concierge\Console\EnsureEggMetadata;
use WisdomIT\Concierge\Jobs\VerifyInstall;
use WisdomIT\Concierge\Livewire\AgentSidebar;
use WisdomIT\Concierge\Services\PostInstallRunner;

class ConciergePluginProvider extends ServiceProvider
{
    /** 사이드바를 띄울 패널. 관리자 패널은 제외한다 — 거기서 남의 서버를 조작할 물건이 아니다. */
    private const SIDEBAR_PANELS = ['app', 'server'];

    public function register(): void
    {
        // 역할 편집 화면에 `wisdomAgent` 권한 묶음을 띄운다(viewList/view/create/update/delete).
        // Root Admin 은 AppServiceProvider 의 Gate::before 로 이미 전부 통과한다.
        Role::registerCustomDefaultPermissions('wisdomAgent');
        Role::registerCustomModelIcon('wisdomAgent', 'tabler-message-chatbot');

        // 콘솔 위 접속자 수 위젯 (#53). Player Counter 와 같은 공식 API — 위치도 같다.
        Console::registerCustomWidgets(ConsoleWidgetPosition::AboveConsole, [PlayerCountWidget::class]);
    }

    public function boot(): void
    {
        // 확인 카드의 재개 상태는 **기본 캐시와 분리해 둔다.**
        //
        // ⚠ `php artisan cache:clear` 는 기본 스토어만 비운다. 그 안에 카드 상태를 두면
        //   배포든 관리자의 손이든 캐시를 한 번 비울 때마다, 마침 카드를 띄워 둔 사용자는
        //   눌러도 "확인이 만료되었습니다"만 보게 된다 — 실제로 그렇게 만들었다.
        //   경로를 따로 쓰면 그 명령에 휩쓸리지 않는다.
        config([
            'cache.stores.' . AgentSidebar::PENDING_STORE => [
                'driver' => 'file',
                'path' => storage_path('framework/cache/concierge'),
            ],
        ]);

        // 유휴 서버 감시(#18). 판정이 "연속 N분"이라 표본이 촘촘할 필요는 없다 —
        // 1분이면 30분 판정에 충분하고 데몬 부하도 작다.
        if ($this->app->runningInConsole()) {
            $this->commands([CheckIdleServers::class, EnsureEggMetadata::class]);
            Schedule::command('concierge:check-idle')->everyMinute()->withoutOverlapping();
        }

        Livewire::component('concierge-sidebar', AgentSidebar::class);

        // 사이드바를 모든 페이지에 붙인다.
        //
        // ⚠ 예전에는 서버 목록의 헤더 액션 → 전용 페이지였다. 그러면 다른 화면(특히 서버 콘솔)
        //   에서 물어보려면 화면을 떠나야 한다. 진단을 도우라고 만든 물건이 정작 로그를 보면서는
        //   못 쓰이는 셈이라, 페이지를 없애고 상시 사이드바로 바꿨다.
        //
        // ⚠ 렌더 훅은 **패널별이 아니라 전역**이다(scopes 는 페이지·리소스 단위다).
        //   그래서 어느 패널인지는 그릴 때 직접 본다.
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, fn (): string => $this->sidebar());

        // 설치가 끝나면 카탈로그의 post_install 을 적용한다(#7).
        // 마인크래프트 EULA, 좀보이드 -Xmx 처럼 **안 하면 첫 기동이 실패하는** 전제들이다.
        //
        // ⚠ `initialInstall` 로 거르면 안 된다. 재설치는 볼륨을 비우므로 이 수정들이 함께
        //   날아간다 — 성공한 **모든** 설치에서 실행해야 한다.
        Event::listen(function (Installed $event) {
            if (!$event->successful) {
                return;
            }

            (new PostInstallRunner(new GameCatalog()))->run($event->server);

            // 설치가 실제로 끝났는지 에이전트가 로그를 읽고 판정한다(#7). Pelican 은 중간에
            // 끊긴 설치도 installed 로 표시하므로 상태만으로는 알 수 없다.
            //
            // ⚠ **사용자가 물어볼 때까지 기다리지 않는다.** 설치는 몇 분 걸리고 그동안 사용자는
            //   다른 화면을 보고 있다 — 실패를 알리는 시점이 "다음에 말을 걸었을 때"면 늦는다.
            VerifyInstall::dispatch($event->server->id)
                ->delay(now()->addSeconds(VerifyInstall::DELAY_SECONDS));
        });
    }

    /**
     * ⚠ **모든 페이지의 렌더 경로에 있다.** 여기서 예외가 새면 패널 전체가 500 이 된다.
     *   플러그인이 반쯤 배포된 구간(마이그레이션 전)에도 화면은 떠야 하므로 통째로 감싼다.
     */
    private function sidebar(): string
    {
        try {
            if (!in_array(Filament::getCurrentPanel()?->getId(), self::SIDEBAR_PANELS, true)) {
                return '';
            }

            if (!AgentSidebar::canAccess()) {
                return '';
            }

            return view('concierge::sidebar-mount')->render();
        } catch (Throwable $exception) {
            report($exception);

            return '';
        }
    }
}
