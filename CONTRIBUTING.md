# Contributing

## Note on the source history

This plugin was built inside a private homelab monorepo and split out for release.
Code comments refer to issue numbers (`#13`, `#16`, `#18` …) from that private tracker —
they are provenance markers, not links you can follow. The reasoning they point at is
summarised here and in the comments themselves.

Parts of this codebase were written with AI assistance.

## Layout

```
src/
  Livewire/AgentSidebar.php      chat UI, one Livewire component on every page
  Services/ChatService           prompt assembly + provider-agnostic tool loop
  Llm/                           provider adapters (Anthropic today; the neutral
                                 message/tool contract lives in LlmProvider)
  Tools/AgentToolbox.php         the 37 tools the model can call
  Services/ServerProvisioner     server creation
  Catalog/GameCatalog.php        reads the catalog (concierge_games table)
  Support/SecretMasker.php       redaction before text reaches the model
resources/catalog/games.yaml     seed for a fresh install; the live catalog is in the database
config/concierge.php   seed defaults + admin dropdown choices
```

The config filename and its config key are both derived from the plugin `id`
(`PluginService`), so renaming the plugin means renaming that file too.

## Design rules

**Write actions never fire on the model's word.** A write tool returns a confirmation
card and suspends; the panel executes only after the user presses the button. When adding
a tool, decide which side of that line it is on first.

**Go through the panel's own services.** Server creation, file writes, backups and
schedules all call the same services the panel's forms call. Reimplementing them drifts
from the panel and loses its validation.

**Mask before the model sees it.** Any text pulled from a server — install logs, config
files — goes through `SecretMasker` first.

**Other plugins are optional.** Every integration is guarded through
`Support\OptionalPlugins::usable()`, which asks the panel whether the plugin's status is
`Enabled`. A missing or disabled plugin must degrade to "this capability is unavailable",
never an exception. See `ModInstaller::providerFor()` and `PlayerCount::available()` for
the pattern.

Every integration's without-plugin behaviour is a decision, made in advance and recorded
in the README's integrations table — never an accident discovered by whoever hits the
missing path first. When adding an integration, write that table row before the code.

🔴 `class_exists` is **not** a substitute: the panel autoloads every plugin's classes
before filtering out disabled ones, so it stays true for a plugin the admin turned off —
whose provider never booted. Measured: with Player Counter disabled, the old
`class_exists` guard let the code call an auto-resolved service with an empty registry,
which broke player counts silently and logged a warning blaming the catalog.

**English in, user's language out.** The system prompt, tool names and tool descriptions
are English — it measures ~40% cheaper in tokens. The reply language is chosen from the
user's panel profile, and the directive appears at both the start and the end of the
prompt (with it in only one place, replies leaked back to English mid-conversation).

## Adding a tool

A tool is not finished when the dispatcher runs it. Four places have to agree, and the
compiler checks none of them:

1. **The definition** — name, description, input schema
2. **The dispatcher** — the `match` arm that runs it
3. **The permission** — `ADMIN_TOOL_PERMISSIONS` for admin tools, `TOOL_PERMISSIONS` for
   server tools. Checked twice: when exposing and when running
4. **The label** — `tool_<name>` in every `lang/*/strings.php`. **Without it the sidebar
   shows the raw translation key while the tool runs** — the user sees
   `concierge::strings.tool_list_catalog_games` instead of "Checking the game catalogue"

Two more when the tool changes something:

5. **`CONFIRM_TOOLS`** — so it produces a confirmation card instead of acting
6. **`ADMIN_CARD_TOOLS`** — if the card does not resolve a server. Miss this and the card
   dies with `server is required`

A quick check for 4, against the running panel:

```php
$tb = new AgentToolbox($user);
foreach (collect($tb->definitions())->pluck('name') as $n) {
    $k = "concierge::strings.tool_{$n}";
    if (trans($k) === $k) { echo "missing label: {$n}\n"; }
}
```

Run it for every locale — a label added to `ko` and forgotten in `en` fails the same way
for English users.

## Measured pitfalls

- 🔴 **Tenant global scopes.** Filament registers global scopes on tenant-panel models
  (`Allocation`, `Backup` …). A tool querying them from outside a tenant request silently
  sees nothing — which looked like "the node has no free ports". Wrap tool bodies in
  `Support\Tenancy::without()`.
- 🔴 **`BuildModificationService` zeroes any limit you don't pass.** Change one limit and
  the rest become 0. Always send the full set.
- 🔴 **`$server->variables` is an `EggVariable`**, not a `ServerVariable` — it carries a
  joined `server_value`. Writing to it edits the egg for every server on the panel.
- 🔴 **Queue workers hold code in memory.** After deploying, restart them or new jobs run
  against stale classes.
- 🔴 **Filament caches its discovered pages** (`bootstrap/cache/filament`). Move or delete
  a page without `filament:cache-components` and the whole panel 500s on a missing class.
- **The daemon's `rename` succeeds on a missing source.** Check existence first.
- **`ToolException` is abstract** — throw `ToolInputException`.
- **Cron is stored in the app timezone.** Convert when displaying to users.
- Deleting a stale confirmation card matters: if the thing it refers to is gone, the card
  has to go too, or the user presses a button that cannot work.

## Verifying against the running panel

Rendering a page server-side catches most integration breakage without a browser:

```php
// php artisan tinker
$u = App\Models\User::first();
Illuminate\Support\Facades\Auth::onceUsingId($u->id);
$resp = app(Illuminate\Contracts\Http\Kernel::class)
    ->handle(Illuminate\Http\Request::create('https://your-panel/admin/concierge', 'GET'));
echo $resp->getStatusCode();
```

For the catalog, the admin screen flags any game whose egg is missing on this panel.
That does not cover variable names: egg variables change upstream, and a wrong one fails
silently (the value is ignored and the egg default is used). `scripts/validate-catalog.py`
still checks a YAML catalog against a dump of your panel's eggs, which is useful when
preparing the shipped seed.

## Coding standards

The panel project uses PHPStan/Larastan and Pint. Keep to the same style.
