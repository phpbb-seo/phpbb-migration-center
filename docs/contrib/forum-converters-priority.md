# Forum converter connector research — priority tiers

Companion to [forum-converters-research.md](forum-converters-research.md), which has the full research per platform. This file just ranks the candidates strongest-first, so it's a five-minute read for "what to build first." Tier placement is based on: does an official first-party reference exist, does it match phpBB 3.3.x's era, is the source SQL/relational (fits `phpbb-migration-center`'s existing PDO connector pattern), and how many independent sources corroborate the schema.

## Tier 1 — start here

**FluxBB**
Official first-party converter ([fluxbb/converter](https://github.com/fluxbb/converter)) with a real `PhpBB_3_0.php` reader, on top of bbPress core, Discourse, and a GitHub-only reference — four independent sources, the most of any platform on this list. Single stable schema lineage (1.4.x/1.5.x, no 2.0 ever shipped) — no version-fragmentation problem to design around. SQL/MySQL, lineage-adjacent to phpBB. No architectural mismatch of any kind.

**Kunena**
Official first-party Joomla importer ([Kunena/com_kunenaimporter](https://github.com/Kunena/com_kunenaimporter)) with *separate, real* `export_phpbb2.php` and `export_phpbb3.php` implementations — the only platform on this list whose official converter explicitly splits phpBB2 vs. phpBB3 handling. Six total sources counting bbPress core's own 1.x/2.x/3.x split, Discourse, SMF converters, and two GitHub hits. The only real complication is Kunena's own version split (1.x/2.x/3.x/4.x/5.x/6.x), which the sources only partially cover — worth confirming which Kunena version(s) to target before starting.

**PunBB**
Four sources (bbPress core explicit v1.4.2, Discourse, SMF converters, and FluxBB's own converter — FluxBB is a PunBB fork). No official PunBB org repo of its own, but that's fine given FluxBB's converter effectively is one, one layer removed. SQL, no version-fragmentation problem for the same reason as FluxBB.

## Tier 2 — solid, but a real complication to plan around

**Flarum**
Three sources, including Discourse's own `flarum_import.rb`, but no first-party Flarum importer exists (confirmed via direct code search of `flarum/core` — zero hits) and the platform's own version history has a real split (1.x vs. current 2.0-rc) with different reference material on each side. Build it, but scope explicitly which Flarum generation you're targeting first.

**Discuz!**
Discourse's `discuz_x.rb` is a genuine, current reference (the modern Discuz X line), replacing what was previously a single 2017 stale GitHub hit of uncertain edition/version. Chinese-language ecosystem, worth confirming free "community edition" vs. commercial-tier schema differences before relying on it.

**Vanilla Forums**
Well-referenced (bbPress core, Discourse, Vanilla's own Porter tool, one GitHub hit) but every non-Porter source is from the old Vanilla 2.x generation. Current Vanilla is a materially different, more SaaS-oriented product — none of the existing references cover it. Fine as a target if "old Vanilla 2.x" is what's actually needed; a real gap if current Vanilla is the goal.

**Advanced Electron Forum (AEF)**
Two sources (bbPress core, SMF converters), both version-unstated. Thin but real, and AEF is a small, stable, unambiguous platform — low risk, just not much material to build from.

## Tier 3 — workable, version or scope mismatch to resolve first

**XMB**
Three sources now (bbPress core, SMF converters' `Xmb/1.9`, and `toldani/sm-transition`), all verified real — better-referenced than it looks at a glance. But no official XMB GitHub org exists at all (confirmed 404), so every source is third-party; none is first-party to XMB itself, and the one GitHub hit was written for one specific board's data rather than as a general tool.

**YaBB / YaBB SE**
Five official SMF-converter files now confirmed (four for classic YaBB across versions 1.0/2/2.1/2.2, one for the separate YaBB SE fork) — genuinely strong material for YaBB's own schema. The gap that keeps this at Tier 3: none of the five read *from* phpBB, so the only phpBB-side mapping reference is still [metapolitics/YaBBse-to-PHPBB-Converter](https://github.com/metapolitics/YaBBse-to-PHPBB-Converter), which explicitly targets **phpBB v2**, not phpBB 3.3.x. The phpBB-3.3-side mapping would need independent verification regardless of how good the YaBB-side material now is.

## Tier 4 — architecturally mismatched or materially thin

**NodeBB**
MongoDB/Redis-backed, not SQL — `phpbb-migration-center`'s entire connector pattern assumes a relational source via PDO. Would need a genuinely different data-access layer, not just a new schema reader, before any of the two GitHub-only references (both several versions stale against current NodeBB v4.15.x) become useful.

**Lemmy**
No data-converter reference in either direction. [LemmyNet/lemmyBB](https://github.com/LemmyNet/lemmyBB) (official, archived) is a genuine "phpBB 3.3-styled Lemmy frontend" and worth reading for how its authors mapped forum/topic/post onto community/post/comment — but it's a UI/design precedent, not schema-reading code. Federated/ActivityPub architecture makes this the biggest structural departure on the whole list, independent of reference material.

**Talkyard**
[debiki/to-talkyard](https://github.com/debiki/to-talkyard) only reads WordPress blog-comment XML exports — not forum software, though it does reveal Talkyard's own internal schema. No forum-specific reference exists.

**Misago**
Confirmed zero reference material in either direction, official or third-party, after checking the account's full repo list directly on top of the four official multi-platform libraries. A real gap — this one would be built from Misago's own documentation/live-instance inspection alone.

## Tier 5 — verified real, but not yet worth prioritizing

Every name below (from the main doc's "New leads" section) has now been individually checked — file fetched, size confirmed real and substantial, not a stub. None of them is ranked above Tier 4 because none has been evaluated yet for the things that actually matter for a build decision: whether the platform is still relevant/alive, whether it's SQL-based, and — critically — almost none of these sources read *from* phpBB (most read INTO SMF, bbPress, Vanilla, or Discourse, same "wrong direction, real schema knowledge" caveat as the main Tier 1–3 sources). Treat this as a verified backlog, not a ranked shortlist.

**Two independent official sources each** (the strongest of this batch, worth a closer look before the rest): **AnswerHub** (Vanilla Porter 11.9KB + Discourse 14.8KB), **JForum** (Vanilla Porter 11.5KB + Discourse 19.0KB), **e107** (bbPress core's `e107v1.php` 19.5KB + SMF's `e107/0.7.7` 22.7KB).

**Everything else, one official source each, all confirmed substantial (4.3–41KB)**: Burningboard/Burningboardlite, Deluxeportal, Dragonfly, Eblah, Fireboard, Fud, Ikonboard, Ldu, Mercuryboard, Minibb, Molyx, Myphp, Mytopix, Openbb, Oxygen, Phpfusion, Phpkit, Phpnuke (+Phpnuke_stories), Quicksilverforum, Seditio, Simpleboard, Snitz, Thwboard, Ubbthreads, Usebb, Vbb, Wbb/Wbblite, Wowbb, Xoops, Zorum, bbpress (SMF reading it as a *source*, the only reverse-direction bbPress reference in this whole document), Drupal7, Mingle, PHPFox3, PHPWind, SimplePress5, AdvancedForum, ASP Playground, CodoForum, esoTalk, ExpressionEngine, FuseTalk, ModX Discuss, Toast, UserVoice, Askbot, AnswerBase, Elgg, FusionForge, GetSatisfaction, Jive (+`jive_api`), Lithium, Muut, Nabble, Telligent, SourceForge, Disqus, MyLittleForum, Bespoke, FriendsMEGplus.

Xoops, Zorum, and reverse-direction bbpress weren't in the original leads list at all — they surfaced only from pulling SMF converters' complete file tree rather than the sample checked the first time through. Xmb, Yabb, and Yabbse also came out of that same pass but were substantial enough to fold directly into the XMB and YaBB SE sections above instead of staying here.

## Excluded from ranking (on phpbb-migration-center's own roadmap)

**SMF** would otherwise be a strong Tier 1 candidate — five official sources including a converter that explicitly targets **phpBB 3.3.x**, the exact version this project cares about ([SimpleMachines/converters](https://github.com/SimpleMachines/converters), `SMF2.1/convert_phpbb33x_to_smf.php`). Along with vBulletin, MyBB, and Invision Community, it's deliberately out of scope here because it's already on `phpbb-migration-center`'s own roadmap — not a comment on how buildable it'd be.

---
*Compiled 09/02/2026 by a community contributor, ranking the research in [forum-converters-research.md](forum-converters-research.md).*
