# Concierge

An AI assistant for **Pelican Panel**, built for the two people who use one:

- **Administrators** run the panel by asking — *"is the node healthy?"*, *"why can't this
  user start their server?"*, *"open ports 27600-27610"*.
- **Players** get their own server without learning the panel — *"make me a Minecraft
  server for six friends"*, *"why can nobody join?"* — creating within the quota you gave
  them through [User Creatable Servers](https://hub.pelican.dev/plugins/user-creatable-servers).

Both talk to the same assistant. What it will do for each of them is decided by their own
panel permissions, and nothing else.

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

**For players.** Pelican's own forms are excellent, but they assume you know what an egg
is, which port a game listens on, and what a Docker image tag means. Plenty of people who
want to run a server for their friends do not, and never will. Give them a quota with User
Creatable Servers and they can describe what they want instead — the assistant sizes it,
picks the port, installs it, and later tells them why nobody can join.

**For administrators.** The answer to an operational question is usually spread across
several screens: the node, its allocations, the user, their role, the activity log. Asking
for it is faster than assembling it, and the assistant reads all of those — then makes the
change too, if your role allows it.

It does not replace the panel. Every action it takes is one the panel already supports,
performed through the panel's own services and permissions.

## What it can do

Around 70 tools. **Nobody gets all of them** — the list handed to the model is assembled
from the requester's own permissions, so it differs per person.

### Running a game server — for whoever owns or was invited to it

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
| Friends | see who is invited to a server, invite with chosen permissions, change or revoke them |
| Repair | reinstall a server whose game files are broken |
| Web | search the web when a question needs current information |

### Running the panel — for administrators, as far as their role reaches

| Area | Examples |
|---|---|
| Diagnose | node health and capacity, wings reachability, ports, users and what they own, roles and what each grants, eggs and their variables, mounts, database and backup hosts, webhooks, API keys, panel health, activity log |
| Operate | maintenance mode, add or reclaim ports, suspend a server |
| People | create an account, grant or revoke roles, transfer a server, edit an account, send a password reset link, clear a stuck two-factor, create roles and set their permissions |
| Build | register a node or a mount |
| Delete | servers, accounts, roles, nodes, mounts — each behind the strongest confirmation in the product |

## What the assistant can do depends on who is asking

The agent mirrors the requester's own authority: whatever they can see and do in the panel
themselves is what it can help with, never more. The tool list is assembled from their real
permissions, and every change still goes through a confirmation card.

| Requester | What the assistant offers |
|---|---|
| **Administrator** | Both tables above — the panel side as far as their role reaches (a node-only admin gets node tools and nothing else), and the servers they can touch |
| **Player, with User Creatable Servers** | Creating servers within the quota you gave them, and full care of the servers they own or were invited to |
| **Player, without it** | **Care only.** Creation is not offered at all — the assistant says an administrator has to make the server, and gets on with running the ones they have |

Permissions are checked twice — when deciding which tools to hand the model, and again when a
tool runs — so a conversation cannot reach past what the person could do on their own screens.

### Anything destructive asks first

Write actions do not happen when the model decides they should. They produce a
**confirmation card** — a short summary of exactly what will change, with a button. The
tool call is suspended until the user presses it. Nothing is executed on the model's word
alone.

A resolved card stays in the transcript as a card — the summary it showed (name, game,
resources, the exact file change) is the record of what was decided, marked approved,
cancelled or expired. An approved action also draws a visible boundary in the
conversation, separating the talk that led up to it from what happened after.

### Secrets are masked

Install logs and config files routinely contain passwords and license keys. Values that
the catalog marks as secret — plus anything that *looks* like a secret by name — are
masked before the text ever reaches the model.

Masking hides a value from the transcript; it does not change where the panel stores it.
For that, install the **[Secret Variables](https://github.com/WisdomIT/pelican-secret-variables)**
plugin — strongly recommended if your users open servers that need a vendor account
(Steam login, license keys). With it, credentials the assistant collects are stored
encrypted; without it they sit in `server_variables` in the clear, readable on the
server's Startup page, and the assistant warns the user of exactly that before they type
one. See the integrations table below.

### Text from tools is data, not orders

A console log carries in-game player chat verbatim; files can be written by anyone with
access; mod listings and web results come from strangers. The assistant is told plainly
that anything arriving in a tool result is **untrusted content to report on, never
instructions to follow** — including text that claims to come from you, an administrator
or "the system". High-risk results are fenced with their provenance, and when something
tries to give it orders the assistant says so in its reply instead of obeying.

That is a mitigation, not a guarantee. The real boundary is structural: the assistant can
only reach what the requester could reach themselves, and every change needs a human to
press the button. Replies also have images stripped, closing a channel that could pull
data out of a conversation without a click.

### Idle servers

An optional watcher notices when a running server has had nobody on it for a while and
tells the owner in their existing chat, offering to stop it. On games that support a
player query it counts players; otherwise it watches network traffic.

## Requirements

- Pelican Panel `v1.0.0-beta35` or newer
- An **LLM provider**. [Anthropic (Claude)](https://console.anthropic.com/) is the
  default and what this plugin is tuned for; **OpenAI**, **Google (Gemini)** and
  **local OpenAI-compatible endpoints** (Ollama, vLLM, llama.cpp) are also supported.
  API usage is billed to your key. See *Cost* below.
- A working queue worker (server creation and install checks run as background jobs)

The `anthropic-ai/sdk` composer package is installed automatically by the panel.

## Install

**From the Hub** — find *Concierge* in the plugin list and install it.

**Manually** — download `concierge.zip` from
[Releases](https://github.com/WisdomIT/pelican-concierge/releases), then either:

- use the **Import** button on the panel's plugin list to upload it, then press
  **Install**; or
- unzip into `plugins/` inside your panel directory
  (`/var/www/pelican/plugins` by default) and run:

  ```bash
  php artisan p:plugin:install
  ```

Keep the zip filename as-is — the panel derives the plugin folder name from it, and it
has to match the `id` in `plugin.json`.

Then find Concierge on the panel's plugin list, press **Settings**, and paste your API key.

## Configuration

Everything lives in that settings dialog; nothing needs an `.env` change. It is split into
six tabs, ordered by the kind of decision each one holds — **Agent connection** (nothing
else matters until this works), **Usage limits**, **Features**, **Environment**,
**Starting points**, **Appearance**. One save covers all six.

| Setting | Tab | Default | Notes |
|---|---|---|---|
| LLM provider | Connection | Anthropic | OpenAI, Google (Gemini) and local OpenAI-compatible endpoints are also supported. Switching keeps each provider's key and model choice. A provider without web search shows that plainly |
| API key | Connection | — | Stored encrypted in the database, per provider. Local endpoints usually need none |
| Model | Connection | `claude-opus-5` | Choices are per provider (`config/concierge.php`); local endpoints take a free-form model name — pick one that supports tool calling |
| Effort | Connection | `medium` | How hard the model thinks. Higher costs more |
| Max tokens | Connection | `8192` | Per reply |
| Usage limit | Limits | 50 messages / user / day | Metric (messages · tokens) × scope (per user · panel-wide) × period (hour · day · week · month). The block message names the limit and its reset time. 0 = unlimited |
| Web search | Features | off | Adds a per-search fee on top of tokens |
| Idle watch | Features | off | Interval, and whether to stop the server or only ask |
| Conversation deletion | Features | off | Users may remove conversations from their own history. Soft: administrators keep the record and usage totals |
| About this deployment | Environment | empty | Facts no tool can discover — the address players connect to, which ports your router forwards, how DNS is set up. Sent with every message, so keep it short. Without it the assistant works but cannot answer "it's running but nobody can join" |
| Starting points | Starting points | seven shipped | The buttons above the message box on an empty chat. Each carries a label and a sentence, both translatable, plus who sees it (everyone · can-create · admin), an optional permission, and an optional path pattern. See below |
| Sidebar colour | Appearance | follow panel | Optionally repaint the assistant sidebar with a colour of its own — the rest of the panel is untouched |

### Starting points

A starting point is a button that writes the user's first sentence for them. Pressing one
sends that sentence **as if the user typed it** — it is recorded as their speech and counts
against their quota.

Two rules are worth knowing before you write one:

- **Do not put the answer in the prompt.** "The games you can create are A, B and C" makes
  the assistant read back something it never checked. Keep it a question; the assistant has
  tools and will look.
- **The path pattern decides relevance, not access.** It answers "is this worth suggesting
  on this screen", so a catalogue suggestion does not surface on a server console. What
  keeps someone out is the visibility level and the optional permission.

Of the starting points that pass all three conditions, the first four appear — so the order
you drag them into is what decides which ones are seen.

Model and effort choices are listed in `config/concierge.php`. When Anthropic
ships a new model you can add it there without touching code.

## Cost

Every message costs money on whichever provider key you configured. Two things keep it
visible and bounded:

- **Usage tracking** — *Admin → Advanced → AI Agent Usage*: the full log of every
  conversation with its messages and tool calls, per-user statistics (total, last day,
  last 7 and 30 days, and how much of their limit is spent), and charts of daily use.
- **A configurable usage limit**, on by default (50 messages per user per day). Count
  messages or tokens, per user or panel-wide, per hour/day/week/month — a panel-wide
  token budget puts a real ceiling on the bill, and its consumption is shown on the
  usage screen.

The system prompt and all tool descriptions are written in English regardless of the
user's language — the same content costs roughly 40% fewer tokens that way. The assistant
still replies in each user's own language, taken from their panel profile.

## Optional integrations

All optional. The rule is simple: **when a plugin is installed and enabled, the assistant
uses it; when it is not, the assistant follows the fallback below.** Each fallback is a
deliberate decision, verified against a running panel — install nothing and the assistant
still works, with exactly these differences:

| Plugin | With it | Without it |
|---|---|---|
| [Player Counter](https://hub.pelican.dev/plugins/player-counter) | Player counts in status answers; player-based idle detection | Counts unavailable (status answers say why); idle detection falls back to network traffic |
| [Minecraft Modrinth](https://hub.pelican.dev/plugins/minecraft-modrinth) | Mod and plugin search & install for Minecraft | Mod tools explain the plugin is missing and that an admin can install it — they do not claim the game is unsupported |
| [Rust uMod](https://hub.pelican.dev/plugins/rust-umod) | Plugin search & install for Rust | Same as above |
| [User Creatable Servers](https://hub.pelican.dev/plugins/user-creatable-servers) | Per-user quotas enforced on creation; ports drawn from its configured range; a delete-server link | **Creation requires admin authority** (the panel's `create server` permission) — ordinary users cannot create at all. Admin creations skip quota and the port pool (reserved ports included) and get 0 backup/database limits; the assistant's reply says so. No delete link |
| [Factorio Mod Installer](https://hub.pelican.dev/plugins/factorio-mod-installer) | A hand-off link to the mod page | No link (mods need a factorio.com account either way) |
| [Secret Variables](https://github.com/WisdomIT/pelican-secret-variables) | Credentials collected in chat (Steam passwords, license keys) land in encrypted storage instead of `server_variables`, for every variable an admin marks *managed* there | Credentials are stored as plain server variables, **readable by the panel operator on the Startup page** — and the assistant says so to the user before they type one |

The settings screen shows each plugin's live status. A disabled plugin behaves like a
missing one, with one exception: port and node-tag ranges come from User Creatable
Servers' *configuration*, which the panel loads even for disabled plugins — so disabling
UCS keeps the port protection while turning off its features.

## Tuning it for your panel

Two things decide what the assistant knows beyond what its tools return, and both are edited from the panel.

**Game catalogue** *(Admin → Advanced → Game catalogue)* — the games the assistant offers,
each mapped to the egg that creates it, with the sizes a user can pick, what to ask them,
and the post-install steps. It ships with 18 games; edit them to match the eggs on your
panel, or add your own. The list flags any game whose egg is missing here, so a broken
mapping shows up before someone tries to create that game.

It lives in the database, so it survives plugin updates. The technical parts (ports,
secret variables, post-install steps) are edited as YAML in one field — their shape
differs per entry, which a form would only make harder to read. Every key is documented in
**[the advanced field reference](https://github.com/WisdomIT/pelican-concierge/blob/main/docs/catalog-advanced.en.md)**
([한국어](https://github.com/WisdomIT/pelican-concierge/blob/main/docs/catalog-advanced.ko.md)),
which the editor also shows in place — the same file, so the two never drift apart.

**About this deployment** *(settings screen, optional)* — free-form text appended to the
system prompt. This is where deployment facts go: the hostname players connect to, which
port range your router forwards, anything the assistant cannot discover through a tool.
Without it, *"it's running but nobody can join"* has no answer — the logs look fine, because
the problem is outside the container.

It is stored in the database, so it survives plugin updates, and it is sent with every
message — keep it to short facts.
**[How to write one](https://github.com/WisdomIT/pelican-concierge/blob/main/docs/deployment-knowledge.en.md)**
([한국어](https://github.com/WisdomIT/pelican-concierge/blob/main/docs/deployment-knowledge.ko.md))
has a worked example, what not to put in it, and why English costs about 40% fewer tokens
for the same content.

## Limitations

- Everything the assistant does happens **as the requester**, inside their own permissions
  and quotas — an ordinary user's assistant has no admin powers, and an administrator's
  reaches exactly as far as their role does, no further.
- Games that need vendor credentials (a Steam account, a FiveM licence) require the user
  to enter those themselves in the panel — the assistant never asks for them in chat.
- File downloads are restricted to a trusted-domain allowlist over HTTPS; private and
  loopback addresses are always refused.
- Panel upgrades can move the internals this plugin builds on. Pin a version you have
  tested before upgrading a production panel.

## Found a bug, or want it to work with another plugin?

**[Open an issue](https://github.com/WisdomIT/pelican-concierge/issues/new/choose).** There
are templates for a bug report, a plugin integration request, and anything else.

A bug report is most useful with the panel version, this plugin's version, and **which
provider and model** you were using — several past bugs were provider-specific, so that is
usually the first thing worth knowing.

⚠ **Never paste an API key, password or token into an issue.** We never need the value to
understand a problem.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE)
