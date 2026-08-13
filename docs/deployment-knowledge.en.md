# About this deployment

*Admin → Advanced → AI Agent settings → About this deployment*

The assistant can read a server's status, its logs, its files and its ports. What it cannot
discover is how **your** deployment is arranged: the address players actually type, which
ports your router forwards, why one hostname works and another does not.

Without those facts, the most common support question in the world —

> *"The server says it's running, but nobody can join."*

— has no answer. The logs look fine, because the problem is outside the container. The
assistant will read them, find nothing, and say so. With those facts it can name the cause
in one turn.

This field is that knowledge. It is free-form text, sent with every message.

---

## What belongs here

Facts that are **true of your deployment and invisible from inside a server**.

- **The address players connect to** — including the shape of it. `play.example.com:27501`
  is a different fact from "the panel is at panel.example.com"
- **Which ports are reachable from outside** — usually a forwarded range. A server on a port
  outside it is unreachable no matter how healthy it looks
- **Ports that are special** — reserved for one game, or in use by something else
- **How DNS is arranged**, when it can break connections in a way nobody would guess: a
  proxied record that passes web traffic but not game traffic, a wildcard that quietly
  catches names you deleted
- **Limits your users will hit** — per-user quotas, a cap on backups, anything an
  administrator has to lift
- **Automatic behaviour that surprises people** — a nightly backup, an idle watcher that
  stops servers

## What does not belong here

- **Credentials of any kind.** Passwords, API keys, tokens. This text goes to the model with
  every message; nothing that must stay secret should be in it
- **Anything a tool already reports** — a server's port, its status, its resource use. The
  assistant reads those live, and a written copy only goes stale
- **Per-server details.** This field is about the deployment, not about one server. Anything
  that changes when a server is created or deleted belongs nowhere near it
- **Long prose.** It is sent every time, so length is a running cost. See below

---

## A worked example

A deployment where the panel is reachable over HTTPS, game traffic is forwarded for one port
range, and one port is reserved for a legacy server:

```markdown
### Connection addresses

- Players connect to `play.example.com:<port>` — the port differs per server; read it with
  get_server_status
- Panel (web): `panel.example.com`
- SFTP (file transfer): `node.example.com` port 2022

### Network setup (router, DNS)

- Ports forwarded on the router: **27500-27599 (TCP and UDP)**, 25565 (reserved), 2022 (SFTP)
- A port **outside that range can never be reached from outside**, however healthy the
  server is
- `panel.*` goes through the CDN proxy; `play.*` and `node.*` are **DNS-only**.
  Game traffic (raw TCP/UDP) **cannot pass through the proxy** — DNS-only is correct for them
- Host firewall rules are bypassed by Docker's published ports, so they are never the cause
  of a connection problem

### "It's running but I can't connect" — diagnosis order

When the status is running but nobody can join, the cause is almost always **outside the
container**. Reading logs will not find it.

1. get_server_status — confirm the power state, and note the port
2. **Is the port inside 27500-27599?** If not, that is the cause, and only an admin can fix it
3. **Is the address right?** It must be `play.example.com:<port>`
4. **Does the game version match?** Minecraft shows "Outdated server/client" on a mismatch —
   read the version from the server's variables and tell them the exact one
5. **In-game password or whitelist** — the config file can be read
6. If all of that is fine it may be DNS, which the user cannot fix; point them at the admin

### When something has to go to the admin

- Port assigned outside the forwarded range
- A game hostname flipped to proxied
- Node out of resources, or no allocations left

### Traits of this deployment

- Server resources are assigned within a per-user limit; only an admin can raise one
- Idle servers are watched — when nobody joins, the assistant speaks first and may stop the
  server
- Backups are capped at 2 per server; an old one has to go before a new one can be made
```

Notice what the example does: it does not describe the network for its own sake. Every line
is there because it changes what the assistant should say to somebody.

---

## Key points when writing yours

**Write facts, not prose.** Short lines the assistant can quote. "Ports 27500-27599 are
forwarded" is usable; "our network is fairly standard" is not.

**Write the diagnosis order for the question you actually get asked.** The example above is
mostly one such order. If your users mostly ask something else, write that instead.

**Say what only an administrator can fix.** It stops the assistant from sending a player in
circles, and it is the difference between "I can't help" and "here is who can".

**Say what looks broken but is not.** A first boot that takes ten minutes, a log line that
repeats while it waits — these produce support questions on their own.

**Keep it current.** It is sent with every message and the assistant believes it. A stale
line here is worse than no line: the assistant will state it with confidence.

---

## Why English costs less

The field is sent with every message, so its length is a running cost — a longer one is
billed on every turn of every conversation.

The same content costs roughly **40% fewer tokens in English** than in Korean, because of
how tokenisers split non-Latin scripts. That is why the system prompt and every tool
description in this plugin is written in English.

**It does not affect the language of replies.** Those follow each user's own panel profile:
a Korean user gets Korean answers from an English deployment note. Write it in whichever
language you will keep accurate — but if you are comfortable in English, it is the cheaper
half of the same decision.

---

## Where it is stored

In the database, with the rest of the settings. It survives plugin updates, which is why it
moved out of a file. It is never included in a release build, so your network details are
not shipped anywhere.
