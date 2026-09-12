# Forum converter connector research — candidate platforms

Research contributed to the [phpbb-migration-center](https://github.com/phpbb-seo/phpbb-migration-center) project, a modular migration framework currently shipping one connector (XenForo → phpBB, Beta) with vBulletin/MyBB/SMF/Invision on its own roadmap. This list covers **free/open-source platforms not already on that roadmap** — candidates for a future connector.

**Hard rule for all of these: reference only, never copy code.** Any connector built from this list gets built from independent understanding of the platform's actual schema (official docs, direct inspection of a real install, or reading a reference implementation purely to extract factual data-model knowledge — table/column names, relationships). This holds regardless of license: several sources below carry a permissive license (MIT, BSD-3-Clause, GPL-2.0) that would technically permit reuse, but a number of others — including several GitHub-only hits and FluxBB's own official converter — ship with **no license file at all**, meaning no rights are granted to copy or adapt their code under any terms. Treat every source the same way: schema and field-mapping knowledge only, never ported or adapted code.

**Core method that's paid off repeatedly**: platforms actively courting migrants from older forum software tend to ship their own official importer libraries, covering dozens of source platforms at once, maintained far better than any single-platform GitHub project. Checking a modern platform's *own* source for "does it import from X" beats searching GitHub for "X to phpBB" every time this has been tried — and it isn't always the platform's *main* repo either: SMF's, FluxBB's, and Kunena's all turned out to live in a separate sibling repo under the same org (`SimpleMachines/converters`, `fluxbb/converter`, `Kunena/com_kunenaimporter`), not the main forum-software repo itself. Six confirmed so far (bbPress, Discourse, Vanilla, SMF, FluxBB, Kunena); Flarum, XMB, PunBB, and Misago checked and confirmed to have none.

## ⭐⭐ Discourse's own import_scripts library — the richest source found so far

**[discourse/discourse](https://github.com/discourse/discourse)**, `script/import_scripts/` and `script/bulk_import/` — Discourse ships official, actively-maintained importers for a huge number of platforms. The phpBB3 importer alone is more sophisticated than anything else found in this research: separate `database_3_0.rb` / `database_3_1.rb` files (version-aware of phpBB's own schema evolution), dedicated importers per content type (`attachment_importer.rb`, `avatar_importer.rb`, `bookmark_importer.rb`, `category_importer.rb`, `message_importer.rb`, `permalink_importer.rb`, `poll_importer.rb`, `post_importer.rb`, `user_importer.rb`), a full BBCode→Markdown converter (`support/bbcode/`), even its own test spec (`spec/script/import_scripts/phpbb3/`). Ruby, not PHP, so not directly portable — but as a *design reference* for how a mature, well-funded project handles phpBB version differences and content transformation, it's the best one available.

Platforms overlapping this document, confirmed present in `script/import_scripts/` or `script/bulk_import/`:

| Platform | File(s) | Cross-references |
|---|---|---|
| FluxBB | `fluxbb.rb` | Third source, alongside bbPress core and the GitHub-only reference |
| PunBB | `punbb.rb` | Second source, alongside bbPress core |
| Kunena | `kunena.rb`, `kunena3.rb` | Adds to bbPress core's Kunena1/2/3 and the two GitHub hits |
| Vanilla | `vanilla.rb`, `vanilla_mysql.rb`, `vanilla_body_parser.rb`, `bulk_import/vanilla.rb` | Adds to bbPress core and the GitHub hit |
| Discuz! | `discuz_x.rb` | Upgrades the one stale Discuz reference below — this one is explicitly the modern **Discuz X** line |
| Flarum | `flarum_import.rb` | New — Discourse can import *from* Flarum, giving a Flarum schema reference Flarum's own core doesn't provide |
| SMF | `smf1.rb`, `smf2.rb`, `bulk_import` has no separate SMF file | Version-split (1.x/2.x), on top of bbPress core's and Vanilla Porter's SMF coverage — see the SMF note near the bottom |
| Phorum | `phorum.rb` | Matches bbPress core's `Phorum.php` |
| JForum | `jforum.rb` | Matches Vanilla Porter's `jforum.php` |
| MyBB | `mybb.rb`, `mybbru.rb` | Roadmap-excluded, noted for completeness (`mybbru` looks like a Russian-locale variant) |
| vBulletin | `vbulletin.rb`, `vbulletin3.rb`, `bulk_import/vbulletin.rb`, `bulk_import/vbulletin5.rb` | Roadmap-excluded |
| Invision | `ipboard.rb`, `ipboard3.rb` | Roadmap-excluded |
| XenForo | `xenforo.rb` | Already `phpbb-migration-center`'s existing connector |

New platform names surfaced here, not otherwise researched in this document, genuinely forum/discussion software: **Askbot, Elgg, FusionForge, GetSatisfaction, Jive (+ `jive_api`), Lithium, Muut, Nabble, Telligent, SourceForge, Disqus, MyLittleForum, Bespoke, FriendsMEGplus.** Some of Discourse's list (Zendesk, Stack Overflow, Yammer, Google/Yahoo Groups, Slack, Question2Answer) are help-desk/Q&A/chat tools rather than bulletin-board forums — same shape of data in places, but probably not the right comparison set for `phpbb-migration-center`'s connector model. None of these individually verified yet — this is a directory listing, not confirmed substantive files the way the platforms in the table above were checked.

## ⭐ Vanilla Porter — Vanilla's own official multi-platform importer

**[vanilla/porter](https://github.com/vanilla/porter)** — "Export legacy forums into a format Vanilla Forums can import." GPL-2.0, 41★, actively maintained (Jan 2025). Has `packages/phpbb2.php` **and** `packages/phpbb3.php` (22.4KB, real logic) — Vanilla's own official phpBB reader.

Also covers, confirmed as substantive files (4.5–15KB each, not stubs): **FluxBB, Kunena, MyBB, NodeBB, PunBB, SMF/SMF2** (all cross-referencing entries elsewhere in this doc), plus genuinely new platform names: **AnswerHub, ASP Playground, CodoForum, Drupal / Drupal7, esoTalk, ExpressionEngine, FuseTalk, JForum, ModX Discuss, Toast, UserVoice, IPB (Invision), vanilla1** (Vanilla's own prior version).

**Bonus for Vanilla itself**: since this tool's whole purpose is producing Vanilla's own import format, its *output* structure (not just the `packages/phpbb3.php` reader) is itself a reference for Vanilla's own expected data model — worth a look if a Vanilla connector ever gets built, on top of the schema references already listed under Vanilla Forums below.

## ⭐ bbPress core's own converter library

**[bbpress/bbPress](https://github.com/bbpress/bbPress)**, `src/includes/admin/converters/` — bbPress ships a built-in, officially-maintained "Import Forums" tool with a dedicated converter class per source platform. Actively maintained (repo pushed within the last day as of this writing), every file substantial (15–31KB, real field-mapping logic), each with a link to bbPress's own Codex docs for that converter.

| Platform | File | Declared version | Notes |
|---|---|---|---|
| FluxBB | `FluxBB.php` | Not stated (since bbPress 2.5.0, ~2016) | Now a third source, see Discourse table above |
| PunBB | `PunBB.php` | **v1.4.2** explicitly | Now a second source |
| AEF | `AEF.php` | Not stated | Only reference found for this platform |
| Kunena | `Kunena1.php` / `Kunena2.php` / `Kunena3.php` | **1.x / 2.x / 3.x** — three separate files | Best version coverage of any platform on this list |
| Vanilla | `Vanilla.php` | **2.0.18.1** explicitly | Near-identical era to the GitHub reference below |
| XMB | `XMB.php` | Not stated | Second reference for this platform |
| phpBB | `phpBB.php` | — | Not needed as a source reference (phpBB's own schema is already well understood), useful only to see this tool's own approach |
| Also present | `Drupal7.php`, `Invision.php`, `Mingle.php`, `MyBB.php`, `PHPFox3.php`, `PHPWind.php`, `Phorum.php`, `SMF.php`, `SimplePress5.php`, `bbPress1.php`, `e107v1.php`, `vBulletin.php` / `vBulletin3.php`, `XenForo.php` | — | MyBB/SMF/Invision/vBulletin/XenForo roadmap-excluded or already covered; Mingle, PHPFox3, PHPWind, e107v1, SimplePress5 not otherwise researched |

**Caveat that applies to all three of the above (Discourse, Vanilla Porter, bbPress core)**: each reads *into* its own destination schema, not phpBB. They document both sides — the source platform's tables *and* the destination's own data model — but since none of the three destinations is phpBB, all of this is schema reference only, never code to adapt, same rule as everything else here.

## ⭐⭐ SMF — has its own official converter repo, correction to an earlier note in this doc

SMF is on `phpbb-migration-center`'s own roadmap, so a new connector for it isn't the point of this entry — but SMF turned out to have real official converter tooling after all, just not where it was first checked.

Earlier in this doc's research the *main* `SimpleMachines/SMF` repo was checked directly and found to have nothing beyond a password-hash compatibility shim (see below) — that check was too narrow. SMF's org has a **separate, dedicated repository for this exact purpose**: **[SimpleMachines/converters](https://github.com/SimpleMachines/converters)** — "Converters to SMF from other forum software," BSD-3-Clause, 14★, pushed July 2025. Organized by target SMF version:

- **`SMF2.1/convert_phpbb33x_to_smf.php`** (29.8KB) — a real, current, actively-relevant find: an official converter reading **phpBB 3.3.x specifically**, the exact version this whole project targets. Also present in the `SMF2.1/` folder: `convert_elkarte11x_to_smf.php` (47.5KB), `convert_mybb18x_to_smf.php` (19KB), `convert_smf20x_to_smf.php` (49.7KB, SMF's own version-upgrade path), `convert_yabb25x_to_smf.php` / `convert_yabb26x_to_smf.php` (55KB each), plus `convert.php` itself (115KB, the shared conversion engine) and `convert_sample_to_smfx.php` (a template for writing a new converter).
- **`SMF2.0/`** — a much larger, older set covering roughly 40 platforms, one folder per platform with version subfolders: `phpBB` (2, 3.0, 3.2 — three separate `.sql`-based converters, each a distinct phpBB era), Aef, Burningboard / Burningboardlite, Deluxeportal, Dragonfly, Drupal (5.7, 6.2), Eblah, Fireboard, Fud, Ikonboard, Invision (2.1, 2.2, 2.3, 3.4, 4.4 — five separate versions), Kunena, Ldu, Mercuryboard, Minibb, Molyx, MyBB (1.0, 1.2, 1.4, 1.8), Myphp, Mytopix, Openbb, Oxygen, Phorum, Phpfusion (6, 7), Phpkit, Phpnuke (+ Phpnuke_stories), Punbb, Quicksilverforum, Seditio, Simpleboard, Snitz, Thwboard, Ubbthreads, Usebb, Vanilla, Vbb, Vbulletin (2.0, 3.5, 3.6, 3.7), Wbb / Wbblite, Wowbb.

This is the same pattern that paid off for bbPress/Discourse/Vanilla above, just one level deeper: it wasn't in the platform's main repo, it was in a sibling repo under the same org. Worth remembering for any future "does X have official converters" check — the main project repo isn't always where it lives.

**License note**: BSD-3-Clause is permissive, but the hard reference-only rule at the top of this document still applies in full — schema/field-mapping knowledge only, never adapted code.

**Direction note**: `convert_phpbb33x_to_smf.php` reads *from* phpBB into SMF — a "phpBB as source" direction that's rarer in this document than the "into phpBB" direction most other entries cover. Worth a look specifically for that reason if a phpBB→SMF direction ever becomes relevant, SMF roadmap-exclusion for a *phpbb-migration-center connector* aside.

**SMF's main repo, for contrast**: `Sources/Maintenance/Migration/` in `SimpleMachines/SMF` is SMF's own version-upgrade tooling (2.0→2.1 schema changes), not a third-party importer. A code search for "phpbb" there turns up exactly one hit unrelated to data import: `Sources/Actions/Login2.php`'s `phpBB3_password_check()`, part of a password-hash compatibility switch that lets a migrated user log in with their old password without SMF verifying or converting any of their actual forum data. That switch (keyed on stored-hash length) also recognizes MyBB, PunBB, vBulletin 3, Invision 2, BurningBoard3, PHP-Fusion, APBoard 2, YaBB SE, Discus, and IkonBoard hash formats — a login-compat shim, a much narrower thing than the `converters` repo above.

Also worth recording since SMF turned up in all three of the earlier official libraries too: bbPress core (`SMF.php`, unversioned), Vanilla Porter (`smf.php` + `smf2.php`, version-split), and Discourse (`smf1.rb` + `smf2.rb`, version-split, most substantial at 21–27KB each) — five independent official sources for SMF's schema in total, counting the two found here.

## ⭐ FluxBB — recommended starting point, now with an official first-party converter

Versions confirmed via `fluxbb/fluxbb` tags: 1.4.0–1.4.13, 1.5.0–1.5.11. No 2.0 was ever released — single stable schema lineage, no version-fragmentation problem to solve.

**[fluxbb/converter](https://github.com/fluxbb/converter)** — found by checking the `fluxbb` GitHub org directly for a sibling repo, the same technique that surfaced SMF's `converters` repo. FluxBB's own official conversion tool (pre-beta, last pushed 2015, no explicit license file). Real, substantial per-platform files under `forums/`: **`PhpBB_3_0.php`** (17.5KB), `PunBB_1.3_1.4.php` (16.1KB — despite the README only mentioning PunBB via an "upgrade path," the code has a real dedicated converter file), `SMF_1_1.php`, `SMF_2.php`, `MyBB_1.php`, `IP_Board_3_2.php`, `PHP_Fusion_7.php`, `miniBB_3_0.php`, `vBulletin_4_1.php`, plus `Merge_FluxBB.php` for combining two FluxBB installs. Also ships `password_converter_mod.txt`, a separate community-contributed mod (not part of the official converter) for converting a user's password hash to FluxBB's format on first post-migration login — the same shape as SMF's `Login2.php` compatibility shim, just implemented as an install-time mod instead of a core switch.

This is now four independent references total: the official converter above, bbPress core, Discourse (`fluxbb.rb`), and [Shervin-QZ/fluxbb2phpbb](https://github.com/Shervin-QZ/fluxbb2phpbb) (May 2025, 1★, no license).

**Why start here**: free, open-source, SQL/MySQL-based (fits `phpbb-migration-center`'s existing PDO connector pattern directly, unlike NodeBB), lineage-adjacent to phpBB, no version split to design around, an official phpBB-3.0-reading converter to reference, and the best-referenced platform on this entire list.

## PunBB — no longer a gap

Four independent official references: bbPress core (`PunBB.php`, explicitly **v1.4.2**), Discourse (`punbb.rb`), SMF's converters repo (`SMF2.0/Punbb/1.0`), and FluxBB's own converter (`PunBB_1.3_1.4.php`, since FluxBB is a PunBB fork — see above). PunBB's own GitHub org (`punbb/punbb`, `punbb-1.2`, `extensions`, `styles`, `langs`) has no converter/import sibling repo of its own.

## Advanced Electron Forum (AEF) — no longer a gap

Two independent official references: bbPress core (`AEF.php`, version unstated) and SMF's converters repo (`SMF2.0/Aef/1.0`).

## ⭐ Kunena (Joomla forum component) — has its own official importer, with real phpBB2 *and* phpBB3 support

**[Kunena/com_kunenaimporter](https://github.com/Kunena/com_kunenaimporter)** — "Import component for Kunena on Joomla," official Kunena org repo, last pushed 2016. Not just one platform: dedicated model files under `models/` for **`export_phpbb2.php`** and **`export_phpbb3.php`** (both real, separate implementations), plus `export_smf2.php`, `export_pnphpbb2.php` (a Joomla-native phpBB2 port, a distinct platform from phpBB2 itself), `export_agora.php`, `export_ccboard.php`, `export_discussions.php`, `export_joobb.php`, `export_ninjaboard.php`, and `export_example.php` as a template for adding new sources. Confirmed by the icon assets shipped alongside it (`phpbb2.png`, `phpbb3.png`, `smf2.png`, `pnphpbb2.png`, `agora.png`, `ccboard.png`, `discussions.png`, `joobb.png`, `ninjaboard.png`) — a genuinely multi-platform official importer, in the same shape as the bbPress/Discourse/Vanilla/SMF libraries above, just scoped to one destination component instead of a whole forum platform.

Now six references total: the official importer above, bbPress core (`Kunena1/2/3.php`, explicit 1.x/2.x/3.x split), Discourse (`kunena.rb`, `kunena3.rb`), SMF's converters repo (`SMF2.0/Kunena/1.5`), and two GitHub hits — [alno/kunena-to-phpbb-converter](https://github.com/alno/kunena-to-phpbb-converter) (2016, no license, forward direction) and [mixerp/phpbb-to-kunena](https://github.com/mixerp/phpbb-to-kunena) (2018, GPL-2.0, reverse direction). Given the official importer's explicit phpBB2/phpBB3 split, Kunena is now one of the better-referenced platforms on this list, alongside FluxBB.

## Vanilla Forums

- Vanilla's own [porter](https://github.com/vanilla/porter) tool (see above) — its own official reference for its own schema, via output format.
- `Vanilla.php` in bbPress core — explicitly targets **Vanilla 2.0.18.1**.
- Discourse's `vanilla.rb` / `vanilla_mysql.rb` / `vanilla_body_parser.rb`.
- SMF's converters repo — `SMF2.0/Vanilla/Vanilla` (version unstated in the path).
- [mike-teehan/Vanilla2-to-phpBB3](https://github.com/mike-teehan/Vanilla2-to-phpBB3) — 2014, targets **Vanilla 2.0.18.4**, nearly identical era to the bbPress core file.
- All non-Porter references are from the old Vanilla 2.x generation; current Vanilla is a materially different, more SaaS-oriented product. None of them cover modern Vanilla.

## XMB (Extreme Message Board)

- **`XMB.php` in bbPress core** — version unstated, real field-mapping logic.
- **SMF's converters repo** — `SMF2.0/Xmb/1.9/xmb_to_smf.sql` (13.9KB, verified real). Second official/quasi-official source.
- [toldani/sm-transition](https://github.com/toldani/sm-transition) — 2019, 1★, no license. Forward direction (XMB→phpBB), written for one specific board's data rather than as a general-purpose tool.
- XMB has no official GitHub org of its own (confirmed — `XMB-Extreme-Message-Board` and `xmb` both 404), so no first-party reference exists; both real sources above are third parties (bbPress and SMF) reading XMB's schema.

## Discuz! — upgraded from a stale reference to a current one

- Discourse's `discuz_x.rb` — explicitly the modern **Discuz X** line, the free community edition's current generation. Supersedes the weak 2017 reference below for anything targeting current Discuz.
- [xuxuechao6/phpbb_to_discuz](https://github.com/xuxuechao6/phpbb_to_discuz) — 2017, 1★, no license, unclear which Discuz edition/version.

## Flarum — real version split to handle, no official first-party importer

Currently at v2.0.0-rc; was 1.x for most of its life until recently. Checked `flarum/core` directly via code search for "phpbb" — **zero results**. Unlike bbPress/Discourse/Vanilla, Flarum's own core ships no built-in importer of any kind. Third-party only:

- [Bokt/flarum-phpbb-migrate](https://github.com/Bokt/flarum-phpbb-migrate) — 2020, 16★, reverse direction (phpBB→Flarum), targets **Flarum 1.x** era.
- [ernestdefoe/importer](https://github.com/ernestdefoe/importer) — Aug 2026, 2★, MIT, targets Flarum as a destination (not source), **current Flarum (2.0-era)**.
- Discourse's `flarum_import.rb` — Discourse importing *from* Flarum, a third, independently-sourced Flarum schema reference.
- These references span different schema eras — would need separate treatment, the same way the XenForo connector has separate `xf20`–`xf23` adapters.

## NodeBB — architecturally mismatched, flag before starting

Currently v4.15.x. **MongoDB/Redis-backed, not SQL** — `phpbb-migration-center`'s entire connector pattern (`xf_db_adapter.php`'s PDO layer) assumes a relational source. Not in bbPress core's or Vanilla Porter's converter lists; not confirmed in Discourse's either. Checked the full `NodeBB` org repo list directly (100+ repos, mostly themes/plugins) — the only import-shaped repos are `nodebb-plugin-import-network54` (a different, unrelated legacy host) and `nodebb-plugin-import-users-csv` (generic user CSV import, no forum content, no phpBB relevance). No dedicated forum-import repo exists org-wide.

- [lefranco/phpBB3toNodeBB](https://github.com/lefranco/phpBB3toNodeBB) — June 2026, reverse direction, likely NodeBB **v4.x**.
- [psychobunny/nodebb-plugin-import-phpbb](https://github.com/psychobunny/nodebb-plugin-import-phpbb) — 2022, 6★, reverse direction, NodeBB schema **circa 2022**.

## YaBB / YaBB SE — much better referenced than first thought, one real gap remains

**SMF's converters repo has real, dedicated coverage of both YaBB lineages** — SMF's roots as a YaBB fork make this the strongest place to check, and checking it directly upgrades this platform substantially:

- `SMF2.0/Yabb/1.0/yabb_to_smf.php` (38.4KB), `Yabb/2/yabb2_to_smf.php` (39.7KB), `Yabb/2.1/yabb21_to_smf.php` (49.7KB), `Yabb/2.2/yabb22_to_smf.php` (45.8KB) — four separate, substantial, version-specific converters for **classic YaBB** (not the SE fork).
- `SMF2.0/Yabbse/1.5/yabbse_to_smf.sql` (21.6KB) — a fifth file, specifically **YaBB SE** (the SE fork, a distinct codebase from classic YaBB, both maintained separately for years).

The one gap that remains: none of these five official files read *from* phpBB — they're all YaBB/YaBB SE → SMF, useful for YaBB's own schema but silent on how YaBB maps to phpBB specifically. The only phpBB-mapping reference is still [metapolitics/YaBBse-to-PHPBB-Converter](https://github.com/metapolitics/YaBBse-to-PHPBB-Converter) (2016, GPL-3.0), and it explicitly targets **phpBB v2** (circa 2006/2012), not phpBB 3.3+. So: strong material for YaBB/YaBB SE's own data model now exists, but the phpBB-3.3-side mapping would still need to be worked out independently.

## bbPress — the destination platform itself, worth its own note

bbPress plugins are distributed on **WordPress.org**, not GitHub.

- **[ForumConverter](https://wordpress.org/plugins/forumconverter/) by Orson Teodoro** — targets **phpBB 3.0.9 → bbPress 2.0**. ~15 years old, abandoned, confirmed destructive — not code to touch, but its FAQ documents that **bbPress has no dedicated forum tables**: forums/topics/replies/attachments all live as WordPress post types inside `wp_posts`/`wp_postmeta`. A fundamentally different data model from every SQL-schema platform above.
- [common-repository/cms2cms-phpbb-to-bbpress-forum-converter](https://github.com/common-repository/cms2cms-phpbb-to-bbpress-forum-converter) — a bridge to the commercial cms2cms.com service, confirmed no local schema logic. Zero usable reference.

## New leads — now individually verified

Every platform name below was previously just a name in a directory listing. Each has now been checked directly (file fetched, size confirmed, content skimmed) — none turned out to be a stub or placeholder; every file listed is a real, substantial converter. This pass also turned up several platforms that weren't in the original leads list at all, found only by pulling the *complete* file listing of `SimpleMachines/converters` rather than the sample checked earlier: **Xmb** (folded into the XMB section above), **Yabb** and **Yabbse** (folded into the YaBB SE section above, which changed substantially as a result), plus three genuinely new ones — **Xoops**, **Zorum**, and SMF's own **bbpress** (0.8.3 and 2.6.6) converter, the only reference found anywhere in this document reading bbPress as a *source* rather than a destination.

**From SMF's converters repo (`SMF2.0/`)** — all `.sql`, all confirmed real (4.9–41KB):

| Platform | Version(s) | Size(s) |
|---|---|---|
| Burningboard / Burningboardlite | 2, 2.3, Lite 1.0 | 18.2KB, 18.2KB, 14.8KB |
| Deluxeportal | 2.0 | 9.7KB |
| Dragonfly | 9 | 26.5KB |
| Eblah | Platinum 9 | 41.0KB (largest file in the whole repo) |
| Fireboard | 1.0.5 RC2 | 9.3KB |
| Fud | 2.6 | 12.2KB |
| Ikonboard | 3.1 | 12.3KB |
| Ldu | 8.0.2, unversioned | 13.3KB, 7.7KB |
| Mercuryboard | 1.1 | 9.6KB |
| Minibb | 2.0 | 4.9KB |
| Molyx | 2.6 | 9.6KB |
| Myphp | 3.0 | 7.2KB |
| Mytopix | 1.2 | 9.6KB |
| Openbb | 1.0 | 9.5KB |
| Oxygen | 1.1 | 12.5KB |
| Phpfusion | 6, 7 | 9.1KB, 9.2KB |
| Phpkit | 1.6 | 16.7KB |
| Phpnuke (+ Phpnuke_stories) | 7.9 | 33.7KB + 10.6KB (`.php`, not `.sql`) |
| Quicksilverforum | 1.2.1 | 10.7KB |
| Seditio | unversioned | 13.1KB |
| Simpleboard | 1.0–1.1 | 8.8KB |
| Snitz | unversioned | 9.3KB |
| Thwboard | 3.0 | 7.1KB |
| Ubbthreads | 6.4–6.5 | 10.6KB |
| Usebb | 0.5.1 | 6.5KB |
| Vbb | 1.0 | 9.1KB |
| Wbb / Wbblite | 3, Lite 2 | 18.5KB, 15.6KB |
| Wowbb | 1.7 | 23.2KB |
| **Xoops** *(new)* | 2, unversioned | 14.2KB, 9.2KB |
| **Zorum** *(new)* | 3 | 9.5KB |
| **bbpress, reverse direction** *(new)* | 0.8.3, 2.6.6 | 4.9KB, 10.9KB |
| e107 | 0.7.7 | 22.7KB — second source, see below |

Drupal (5.7, 6.2, 11.6–12.1KB each) and Phorum (5, 7.2KB) also confirmed real here, overlapping the bbPress-core leads below with different platform versions.

**From bbPress core's converter list** — all `.php`, all confirmed real (13–20KB):

| Platform | File | Size |
|---|---|---|
| Drupal7 | `Drupal7.php` | 19.8KB — a different Drupal version than SMF's Drupal 5.7/6.2 above |
| Mingle | `Mingle.php` | 13.1KB |
| PHPFox3 | `PHPFox3.php` | 17.7KB |
| PHPWind | `PHPWind.php` | 16.6KB |
| e107v1 | `e107v1.php` | 19.5KB — second source, alongside SMF's `e107/0.7.7` above |
| SimplePress5 | `SimplePress5.php` | 18.9KB |

**From Vanilla Porter's `packages/`** — all `.php`, all confirmed real (4.6–14.9KB):

AnswerHub (11.9KB — also in Discourse's list below, two sources), ASP Playground (5.8KB), CodoForum (4.6KB), esoTalk (7.3KB), ExpressionEngine (14.9KB), FuseTalk (11.3KB), JForum (11.5KB — also in Discourse's list below, two sources), ModX Discuss (9.5KB), Toast (5.4KB), UserVoice (9.2KB). (AdvancedForum, 5.2KB, also confirmed real — a Vanilla Porter platform not previously listed.)

**From Discourse's `script/import_scripts/`** — all `.rb`, all confirmed real (4.3–33.9KB):

Askbot (7.5KB), Elgg (6.7KB), FusionForge (6.7KB), GetSatisfaction (10.1KB), Jive (11.1KB) + `jive_api` (14.8KB), Lithium (33.9KB), Muut (4.7KB), Nabble (8.7KB), Telligent (28.6KB), SourceForge (4.3KB), Disqus (5.6KB), MyLittleForum (33.7KB), Bespoke (`bespoke_1.rb`, 6.3KB), FriendsMEGplus (23.5KB), AnswerBase (9.8KB, not previously listed). AnswerHub (14.8KB) and JForum (19.0KB) confirmed here too — cross-referencing the Vanilla Porter entries above, both now have two independent official sources. (Discourse's directory also includes several help-desk/Q&A/chat tools — Zendesk, Stack Overflow, Yammer, Google/Yahoo Groups, Slack, Question2Answer — real files, but probably not the right comparison set for a bulletin-board-shaped connector.)

**Still just names, not yet checked**: none — every platform surfaced by the four official libraries has now been individually verified. Any *further* platform names would come from a fresh source not yet checked.

## Lemmy — not a converter, but a genuinely useful phpBB-shaped reference exists

**[LemmyNet/lemmyBB](https://github.com/LemmyNet/lemmyBB)** — official LemmyNet org repo, described in its own README as "**A Lemmy frontend based on phpBB 3.3**." Archived and explicitly unmaintained ("not compatible with current Lemmy versions"), and it isn't a data converter — no import/export code, just a phpBB-3.3-styled UI layer sitting on top of Lemmy's own API and data model. Still directly useful: it's a real, official attempt to map phpBB 3.3's concepts (forums, topics, posts) onto Lemmy's (communities, posts, comments), by developers who had to solve that exact mapping problem. Worth reading for design ideas on a phpBB↔Lemmy connector even though there's no schema-reading code to reference here. This meaningfully changes Lemmy from a hard "zero reference material" gap to "no converter, but a mapping precedent exists" — found only by checking the LemmyNet org's repo list directly, the same technique that worked everywhere else in this document.

Lemmy remains architecturally the furthest platform on this list from a normal SQL-schema forum — federated/ActivityPub-based, closer to a link aggregator than a bulletin board — so even with `lemmyBB` as a reference, a connector here would be a bigger design departure than anything else here.

## Talkyard — narrow reference exists, not a forum converter

**[debiki/to-talkyard](https://github.com/debiki/to-talkyard)** — official Talkyard-author repo (`debiki` account), last pushed 2018. Reads a WordPress **blog** XML export (posts + comments) and writes Talkyard's own internal `SiteData` format (`pages`, `posts`, `guests`). Not a forum-software converter — WordPress comments, not phpBB/vBulletin/etc. — but it's a real, if narrow, official look at Talkyard's own destination schema, and confirms the account has thought about import tooling in this shape before.

## Misago — still no reference material exists

Checked the account's repo list directly (just `Misago` itself, no sibling importer repo) on top of the earlier GitHub-wide search and the four official libraries (bbPress, Discourse, Vanilla Porter, SMF converters) — a real gap, no first-party or third-party reference found in either direction.

## Already excluded (on phpbb-migration-center's own roadmap)

vBulletin, MyBB, SMF, Invision Community — deliberately not covered as build candidates here. All four, plus XenForo (the existing connector), also have converters in bbPress core, Discourse, Vanilla Porter, and/or SMF's own `converters` repo, for what it's worth.

---
*Compiled 09/02/2026 by a community contributor, per CONTRIBUTING.md's welcome for documentation and new source-platform connector research. Offered for the maintainers' consideration — not tied to any specific implementation timeline.*
