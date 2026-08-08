# Wisdom AI Assistant

An AI assistant for **Pelican Panel**. Your users describe what they want in plain
language — *"make me a Minecraft server for six friends"*, *"why can nobody join?"* —
and the assistant does it, or explains what is wrong.

It runs as a sidebar on every panel page, so nobody has to leave what they were doing.

---

## What is Pelican Panel?

[Pelican](https://pelican.dev) is a free, open-source **game server control panel**.
It gives you a web interface to create and manage game servers — Minecraft, Terraria,
Palworld, Valheim, Factorio and many more — with each server running in its own isolated
Docker container.

**Plugins** extend the panel without modifying any of its core files, and the
**[Pelican Hub](https://hub.pelican.dev/plugins)** is the official marketplace where you
find and install them.

If you don't run a Pelican Panel, this repository won't be useful to you on its own.

---

## Why

Pelican's own forms are excellent, but they assume you know what an egg is, which port a
game listens on, and what a Docker image tag means. Plenty of people who want to run a
server for their friends do not, and never will.

This plugin is aimed squarely at them. It does not replace the panel — every action it
takes is one the panel already supports, performed through the panel's own services.

## What it can do

37 tools, grouped by what they touch:

| Area | Examples |
|---|---|
| Read | server status, resource use, players online, logs, config files, schedules, backups |
| Power | start, stop, restart |
| Create | pick a game, size it against the user's quota, allocate a port, install |
| Files | read, edit, create, delete, download from a trusted source |
| Mods | search and install (Modrinth / uMod), update, remove |
| Backups | create, restore, delete |
| Schedules | create, toggle, delete |
| Startup | change egg variables, Docker image, startup command |
| Console | send commands |
| Web | search the web when a question needs current information |

### Anything destructive asks first

Write actions do not happen when the model decides they should. They produce a
**confirmation card** — a short summary of exactly what will change, with a button. The
tool call is suspended until the user presses it. Nothing is executed on the model's word
alone.

### Secrets are masked

Install logs and config files routinely contain passwords and license keys. Values that
the catalog marks as secret — plus anything that *looks* like a secret by name — are
masked before the text ever reaches the model.

### Idle servers

An optional watcher notices when a running server has had nobody on it for a while and
tells the owner in their existing chat, offering to stop it. On games that support a
player query it counts players; otherwise it watches network traffic.

## Requirements

- Pelican Panel `v1.0.0-beta35` or newer
- An **[Anthropic API key](https://console.anthropic.com/)** — this plugin calls the
  Claude API, and that usage is billed to your key. See *Cost* below.
- A working queue worker (server creation and install checks run as background jobs)

The `anthropic-ai/sdk` composer package is installed automatically by the panel.

## Install

**From the Hub** — find *Wisdom AI Assistant* in the plugin list and install it.

**Manually** — download `wisdom-ai-assistant.zip` from
[Releases](https://github.com/WisdomIT/pelican-wisdom-ai-assistant/releases), then either:

- use the **Import** button on the panel's plugin list to upload it, then press
  **Install**; or
- unzip into `plugins/` inside your panel directory
  (`/var/www/pelican/plugins` by default) and run:

  ```bash
  php artisan p:plugin:install
  ```

Keep the zip filename as-is — the panel derives the plugin folder name from it, and it
has to match the `id` in `plugin.json`.

Then open **Admin → Advanced → AI Assistant Settings** and paste your API key.

## Configuration

Everything lives on the admin settings page; nothing needs an `.env` change.

| Setting | Default | Notes |
|---|---|---|
| API key | — | Stored encrypted in the database |
| Model | `claude-opus-5` | Sonnet and Haiku are also offered; cheaper, less capable at multi-step tool use |
| Effort | `medium` | How hard the model thinks. Higher costs more |
| Max tokens | `8192` | Per reply |
| Daily message limit | `50` | Per user |
| Idle watch | off | Interval, and whether to stop the server or only ask |
| Web search | off | Adds a per-search fee on top of tokens |

Model and effort choices are listed in `config/wisdom-ai-assistant.php`. When Anthropic
ships a new model you can add it there without touching code.

## Cost

Every message costs money on your Anthropic key. Two things keep it visible and bounded:

- **Usage tracking** — *Admin → Advanced → AI Assistant Usage* shows tokens and estimated
  cost per user and per conversation, with the full message and tool log.
- **A per-user daily message limit**, on by default.

The system prompt and all tool descriptions are written in English regardless of the
user's language — the same content costs roughly 40% fewer tokens that way. The assistant
still replies in each user's own language, taken from their panel profile.

## Optional integrations

All optional. If a plugin is absent the matching capability disappears and nothing else
changes.

| Plugin | Adds |
|---|---|
| [Player Counter](https://hub.pelican.dev/plugins) | Player counts, and player-based idle detection |
| [Minecraft Modrinth](https://hub.pelican.dev/plugins) | Mod and plugin install for Minecraft |
| [Rust uMod](https://hub.pelican.dev/plugins) | Plugin install for Rust |
| [User Creatable Servers](https://hub.pelican.dev/plugins) | Per-user resource quotas are honoured when sizing a new server |
| Factorio Mod Installer | A link to the mod page (Factorio mods need an account, so the assistant hands off) |

## Tuning it for your panel

Two files decide what the assistant knows.

**`resources/catalog/games.yaml`** — 18 games, mapped to the eggs they need, with query
type, ports, which variables are secret, and post-install steps. This is what the
assistant offers when someone asks for a server. Edit it to match the eggs on your panel;
`resources/catalog/README.md` documents the format, and `scripts/validate-catalog.py`
checks a catalog against your panel's actual eggs.

**`resources/knowledge/agent.md`** *(optional, not shipped)* — free-form text appended to
the system prompt. This is where deployment facts go: the hostname players connect to,
which port range your router forwards, anything the assistant cannot discover through a
tool. Without it the assistant works but will not know your network.

## Limitations

- Everything the assistant does happens as the **panel user**, inside their permissions
  and quotas. It has no admin powers.
- Games that need vendor credentials (a Steam account, a FiveM licence) require the user
  to enter those themselves in the panel — the assistant never asks for them in chat.
- File downloads are restricted to a trusted-domain allowlist over HTTPS; private and
  loopback addresses are always refused.
- Panel upgrades can move the internals this plugin builds on. Pin a version you have
  tested before upgrading a production panel.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
