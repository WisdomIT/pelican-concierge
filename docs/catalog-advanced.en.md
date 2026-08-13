# Catalogue: the advanced field

Everything in a catalogue entry that is not a name, an egg, a size or a question lives in
one YAML field. This page is what each key does, what shape it takes, and what happens if
you leave it out.

Every key is optional. Leave out anything you do not need.

The editor checks what you write against this page and against the egg you picked, so a
wrong variable name or a broken shape is reported before you save — not when someone tries
to create a server.

---

## Player counts

### `query`

*Text · one of `minecraft_java`, `minecraft_bedrock`, `source`, `goldsrc`, `cfx`, `palworld`*

Which protocol to ask this game for its player list. The assistant uses the count to answer
"how many people are on?" and to decide a server is idle.

**Needs the [Player Counter](https://hub.pelican.dev/plugins/player-counter) plugin.**
Without it, this key is ignored: player counts are unavailable and idle detection falls
back to watching network traffic instead.

Leave it out for games that expose no query protocol. Then the same fallback applies.

```yaml
query: minecraft_java
```

### `query_port_variable`

*Text · the name of an egg variable*

Games do not always answer queries on the port players connect to. When the query port is
held in an egg variable, name it here and that value is used instead of the main port.

Leave it out and the server's own port is queried.

```yaml
query_port_variable: QUERY_PORT
```

---

## What the player is given

### `player_var`

*Text, or `null`*

The egg variable that holds the maximum number of players. When someone picks a size, that
size's player count is written into this variable.

Use `null` — and say so explicitly — when the game has **no such variable**. Then the size
is expressed only through the resources it grants (memory, disk, CPU), which is how
Minecraft works. Writing `null` records that you checked; leaving the key out entirely says
nothing.

The value is clamped by [`caps`](#caps) if a cap exists for that variable.

```yaml
player_var: MAX_PLAYERS
```

### `caps`

*A set of keys and values · egg variable name → whole number*

An upper bound for a variable, applied whether the value came from a size or from the
user's own answer. Anything above it is refused with a clear reason rather than passed to
the egg.

This exists because egg rules are often too loose to protect anything: a `MAX_PLAYERS`
whose only rule is `min:1` will happily accept 10000, and the server then dies on its
first busy evening.

```yaml
caps:
  MAX_PLAYERS: 32
```

### `defaults`

*A set of keys and values · egg variable name → a single value*

Values written into egg variables at creation without asking the user. Use it for the
choices that have one sensible answer, so the conversation does not ask about them.

The value must be a single value — a string, a number, a boolean. Not a list or a nested
block: it goes straight into an environment variable.

```yaml
defaults:
  BUILD_NUMBER: latest
  SERVER_JARFILE: server.jar
```

---

## Ports

### `ports`

*A set of keys and values*

How many ports this game needs and what is done with them.

| Key | Type | What it does |
|---|---|---|
| `count` | Whole number, at least 1 | How many ports to reserve. Required |
| `protocol` | List of `tcp` and/or `udp` | Which protocols the ports must allow |
| `contiguous` | `true` / `false` | When true, the reserved ports must be **consecutive** — for games that open the next port up by themselves. Default `false` |
| `derive` | List | Writes the extra ports into egg variables (below) |

Each entry in `derive`:

| Key | Type | What it does |
|---|---|---|
| `env` | Text | The egg variable to write the port into |
| `from` | `allocation` | Where the port comes from. Only `allocation` today |
| `index` | Whole number from 0 | Which reserved port to use. `0` is the main port, so extras start at `1` |

An `index` that is not below `count` points at a port that was never reserved — the editor
reports this, because otherwise creation fails with nothing to explain it.

```yaml
ports:
  count: 3
  protocol: [udp]
  contiguous: true
  derive:
    - { env: QUERY_PORT, from: allocation, index: 1 }
    - { env: RCON_PORT, from: allocation, index: 2 }
```

---

## Secrets

### `secrets`

*List of egg variable names*

Values that must never reach the model. When one of these appears in a console line, a
config file or an install log, it is replaced before the text is sent.

**No plugin needed** — this plugin does the masking.

There is a name-pattern safety net (`PASSWORD`, `TOKEN`, `_KEY`, …), but it is a last line
of defence, not a substitute: it misses secrets whose names do not look like secrets, and
the catalogue is the only place that knows what is secret for a particular game. The editor
warns when the egg has secret-looking variables you have not declared.

> ⚠ **Masking is not storage.** Listing a variable here keeps its value out of the
> conversation; it does not encrypt it. The value still sits in `server_variables` where
> the panel operator can read it. To store it encrypted, mark that variable as *managed* in
> the [Secret Variables](https://github.com/WisdomIT/pelican-secret-variables) plugin —
> a separate decision from listing it here.

```yaml
secrets: [SERVER_PASSWORD, ADMIN_PASSWORD]
```

---

## Mods

### `mods`

*A set of keys and values*

Whether this game takes mods, and how. The assistant uses it to explain what is possible —
it does not install anything from these values on its own.

| Key | Type | What it does |
|---|---|---|
| `supported` | `true` / `false` | Whether mods are possible at all |
| `kind` | One of `plugin`, `mod`, `addon`, `workshop_id`, `resource`, `config_ini` | How mods reach the server (below) |
| `path` | Text | Where mod files live, for the kinds that use files |
| `note` | Text | A sentence the assistant can pass on — what a player has to do |
| `config_path` | Text | For `config_ini`: the file that lists the mods |
| `keys` | A set of keys and values | For `config_ini`: which setting holds what |

What each `kind` means:

- **`plugin`** — server plugins, installed into a directory (Minecraft/Paper, Rust/oxide)
- **`mod`** — game mods proper, usually needing a matching client (Fabric, Forge, Factorio)
- **`addon`** — Source-engine style additions (Garry's Mod)
- **`workshop_id`** — mods are chosen by putting a Workshop id in an egg variable
- **`resource`** — resources dropped into a folder (FiveM)
- **`config_ini`** — mods are enabled by editing an ini file, not by copying files

Searching and installing mods **needs
[Minecraft Modrinth](https://hub.pelican.dev/plugins/minecraft-modrinth) or
[Rust uMod](https://hub.pelican.dev/plugins/rust-umod)**. Without the relevant plugin the
assistant says an administrator has to install it, rather than claiming the game has no
mod support.

```yaml
mods:
  supported: true
  kind: plugin
  path: plugins/
  note: Installable from the panel's Modrinth tab
```

---

## After installation

### `post_install`

*List*

Steps carried out automatically once the install finishes, before the user is told the
server is ready. Use it for the things a player should never have to know about.

Each step has a `type`, and the type decides which other keys are required.

**`file_replace`** — replace text in a file.

| Key | Type | Required |
|---|---|---|
| `path` | Text — path inside the server | yes |
| `from` | Text — what to look for | yes |
| `to` | Text — what to write instead | yes |
| `reason` | Text — why this is needed | no, but write it |

**`json_vmarg`** — set a JVM argument in a JSON launcher file, scaled to the server's memory.

| Key | Type | Required |
|---|---|---|
| `path` | Text — path inside the server | yes |
| `arg` | Text — the argument, e.g. `-Xmx` | yes |
| `value_ratio` | Number between 0 and 1 — share of the memory limit | yes |
| `reason` | Text | no, but write it |

```yaml
post_install:
  - type: file_replace
    path: eula.txt
    from: eula=false
    to: eula=true
    reason: the first boot fails silently until this is accepted
  - type: json_vmarg
    path: ProjectZomboid64.json
    arg: -Xmx
    value_ratio: 0.62
    reason: the egg hard-codes -Xmx8g, which the container kills
```

---

## Install and images

### `install_min_mb`

*Whole number — megabytes*

Roughly how big a finished install is. The panel marks an install that was cut off partway
as *installed* all the same, so size is often the only evidence that something went wrong.
Recorded here for that judgement.

```yaml
install_min_mb: 4000
```

### `java_from`

*Text — the name of an egg variable*

Names the variable holding the game version, for games where the version decides which
runtime is needed. Two things use it:

- **The Docker image** — the version is read from that variable and the matching Java image
  is chosen. Without this, the egg's own default image is used, which may be wrong for the
  version the player picked
- **The server's name** — a version-bearing game is named "Minecraft 1.21" rather than
  "Minecraft, 4 players"

```yaml
java_from: MINECRAFT_VERSION
```

### `image`

*Text — a Docker image tag*

A fixed image for this game, for the case where the version does not decide it. Ignored
when `java_from` is set. Leave both out to use the egg's default.

---

## Notes for people

### `notes`

*List of text*

Things worth telling the user, in their own words. The first one is shown on the creation
card, so put the thing they most need to know first — a long install, a licence they must
supply, a first boot that looks stuck but is not.

```yaml
notes:
  - The first start takes over ten minutes. It is not stuck.
```

### `verified`

*A set of keys and values*

What you actually observed when you tested this game. Nothing reads it — it is a record for
whoever edits the entry next, so they know what "working" looked like.

| Key | Meaning |
|---|---|
| `install` | What a finished install looked like — size, duration |
| `boot` | What a healthy first boot looked like |

```yaml
verified: { install: "5.8GB", boot: "map generation, RCON bound on 28016" }
```
