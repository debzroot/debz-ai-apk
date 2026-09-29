# Debz AI CLI Reference

Live sources when anything looks stale: `debz --help`, `debz <command> --help`,
~/debz-ai/README.mdreference/cli-commands

### Global Flags

```
debz [flags] [command]        (no subcommand = interactive chat)

  --version, -V             Show version
  -z, --oneshot PROMPT      One-shot: print ONLY the final response (for scripts/pipes)
  -m MODEL  --provider P    Model/provider override for this invocation
  -t, --toolsets LIST       Comma-separated toolsets for this invocation
  --resume, -r SESSION      Resume session by ID or title
  --continue, -c [NAME]     Resume by name, or most recent session
  --worktree, -w            Isolated git worktree mode (parallel agents)
  --skills, -s SKILL        Preload skills (comma-separate or repeat)
  --profile, -p NAME        Use a named profile
  --yolo                    Skip dangerous command approval
  --tui / --cli             Force the Ink TUI / classic REPL
  --ignore-rules            Skip AGENTS.md/SOUL.md/memory/skill injection
  --safe-mode               Disable ALL customizations (troubleshooting)
  --pass-session-id         Include session ID in system prompt
```

### Chat

```
debz chat [flags]
  -q, --query TEXT          Single query, non-interactive
  --image PATH              Attach a local image to a single query
  -Q, --quiet               Suppress banner, spinner, tool previews
  --checkpoints             Enable filesystem checkpoints (/rollback)
  --max-turns N             Cap tool-calling iterations
  --source TAG              Session source tag (default: cli)
```
(plus the global flags above)

### Configuration

```
debz setup [section]      Wizard (model|tts|terminal|gateway|tools|agent)
debz model                Interactive model/provider picker
debz fallback [add|remove|list]  Fallback provider chain
debz config [show|edit|get|set|unset|path|env-path|check|migrate]
debz login / logout       OAuth sign-in / clear stored auth
debz doctor [--fix]       Check dependencies and config
debz status [--all]       Component status
```

### Tools & Skills

```
debz tools [list|enable NAME|disable NAME]   Per-platform toolsets (curses UI with no args)

debz skills list|browse|search QUERY|inspect ID
debz skills install ID    Hub identifier OR a direct https://…/SKILL.md URL
debz skills config        Enable/disable skills per platform
debz skills check|update|uninstall|publish PATH
debz skills tap add REPO  Add a GitHub repo as a skill source
debz bundles              Skill bundles (one /<name> alias loads several skills)
```

### MCP Servers

```
debz mcp add NAME (--url or --command) | remove | list | test NAME
debz mcp catalog | install NAME     Curated catalog install
debz mcp configure NAME             Toggle tool selection
debz mcp serve                      Run Debz AI as an MCP server
```
Details (transport, tool discovery, catalog): `references/native-mcp.md`.

### Gateway (Messaging Platforms)

```
debz gateway run|install|start|stop|restart|status|setup
```

20+ platforms: Telegram, Discord, Slack, WhatsApp (Baileys + Business Cloud API), iMessage (Photon — `debz photon setup`), Signal, Email, SMS, Matrix, Mattermost, Teams, LINE, SimpleX, ntfy, Google Chat, Home Assistant, DingTalk, Feishu, WeCom, Weixin, API Server, Webhooks. Open WebUI connects via the API Server adapter. Most adapters ship under `plugins/platforms/`.
Docs: ~/debz-ai/README.mduser-guide/messaging/

### Sessions

```
debz sessions list|browse|rename ID TITLE|delete ID|export OUT|prune|stats
```

### Cron / Webhooks

```
debz cron list|create SCHED|edit ID|pause|resume|run ID|remove|status
    Schedules: '30m', 'every 2h', '0 9 * * *', ISO timestamp
debz webhook subscribe NAME|list|remove NAME|test NAME
```
Webhook payloads/routes: `references/webhooks.md`.

### Profiles

```
debz profile list|create NAME (--clone|--clone-all|--clone-from)|use|show|delete
debz profile rename A B | alias NAME | export NAME | import FILE
```

### Credentials & Pools

```
debz auth                 Interactive credential manager
debz auth add [PROVIDER]  Add OAuth or API-key credential (nous, openai-codex, qwen-oauth, …)
debz auth list|remove P IDX|reset PROVIDER|status
```
Multiple credentials per provider form a pool that rotates automatically and skips exhausted keys.

### Other

```
debz desktop / gui        Native desktop app
debz dashboard            Web admin panel + embedded chat (--stop / --status)
debz proxy                OpenAI-compatible local proxy backed by an OAuth provider
debz portal               Quick setup / sign in via Nous Portal
debz kanban <verb>        Multi-agent work-queue board
debz project              Named multi-folder workspaces
debz skin list|use|set    Switch/tweak skins (see references/themes.md)
debz pets <verb>          Pet mascots (see references/petdex.md)
debz memory setup|status|off|reset   Memory provider
debz secrets bitwarden|onepassword   External secret stores
debz moa                  Mixture-of-Agents slots
debz hooks / security / backup / import / checkpoints / console
debz logs [-f] [errors]   View agent/error logs
debz send                 One-off message through a gateway platform
debz pairing / plugins / insights / journey / computer-use
debz acp                  ACP server (IDE integration)
debz completion bash|zsh|fish
debz update / uninstall / claw migrate
```

Plugin- and provider-supplied subcommands (e.g. `debz photon setup`) only appear once their plugin is installed/active.

### Where to Find Things

| Looking for... | Location |
|---|---|
| Config options | `debz config edit` · [Configuration docs](~/debz-ai/README.mduser-guide/configuration) |
| Tools / toolsets | `debz tools list` · [Tools reference](~/debz-ai/README.mdreference/tools-reference) |
| Skills catalog | `debz skills browse` · [Skills catalog](~/debz-ai/README.mdreference/skills-catalog) |
| Provider setup | `debz model` · [Providers guide](~/debz-ai/README.mdintegrations/providers) |
| Env variables | `debz config env-path` · [Env vars reference](~/debz-ai/README.mdreference/environment-variables) |
| Gateway logs | `~/debz-ai/logs/gateway.log` (or `debz logs`) |
| Sessions | `debz sessions browse` (reads state.db) |
