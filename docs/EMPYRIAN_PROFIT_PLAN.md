# Empyrian Profit Plan — Finalized

**Source:** DeepDiary project 19 ("Empyrian - CEO backlog"), task 135. v1 drafted 2026-09-21 by
`ceo-empyrian`; finalized 2026-10-26 by the Board. Status: **Done**. The task is closed in DeepDiary
and the summary is posted to the "Empyrian CEO Office" Converse room.

## What the app is

**Join Empyrian so that you can listen to and share music, and sell your music to your fans.**

Live state on 2026-10-26: https://empyrian.net returns 200 in about 1.9 s. WordPress with the Musik
theme on Hostinger, not yet behind Cloudflare (task 195 is blocked). Already built: Mynger sign-in,
uploads into each user's own Mynger S3 folder (`plugins/mynger-integration`), 141 playable tracks
(Play Block) and Easy Digital Downloads (EDD). EDD's checkout (`/checkout-2/`) defaults to the
"manual" Store Gateway, which records orders without taking money, and no track has a price yet.
No CI.

## Group money rules (Board, 2026-10-26)

1. Every app must be profitable on its own costs, as if it ran alone.
2. The 10% withdrawal fee belongs to the app that made the sale.
3. Every revenue generator has its own entry in OpenBank's money-movement table.
4. All money moves through BlockBank/OpenBank. Users spend only COA inside the apps.

## Revenue model (approved)

| Generator | Movement | How Empyrian earns |
|---|---|---|
| Track and album sales through EDD | `empyrian/TRACK_PURCHASE` | 10% when artists withdraw; artists keep 100% |
| Tips to artists | `empyrian/DONATION` | 10% when artists withdraw |

How a purchase works: a signed-in listener buys a track priced in COA through EDD's normal cart. At
checkout they choose **Pay with COA**, a payment method in our own plugin. The plugin asks OpenBank
to move the COA from buyer to artist, tagged `empyrian/TRACK_PURCHASE`. On success, EDD completes the
order and unlocks the download. Without enough COA, the buyer gets a "Top up on BlockBank" button.
Tips use the same path, tagged `empyrian/DONATION`.

Dropped from the draft: storage tiers (storage belongs to WebDocs).

## The draft's asks (answered)

1. **Profit date:** 2027-12-31. Start: 2027-01-01.
2. **Payments:** BlockBank/OpenBank is the rail (group rule 4).
3. **Storage:** audio stays on Mynger S3 (pennies today); no chargeback needed.

## Costs (resolved 2026-10-26)

| Line | Monthly |
|---|---|
| Hostinger hosting (R1,347.49 a year) | ≈ R112 |
| empyrian.net domain (R303 a year) | ≈ R25 |
| AI engineering: one Claude Pro seat for the daily ticket agent | R381 |
| **Total** | **≈ R518/month** |

The Musik theme was a one-off R1,191 (2024). The Dec 2025 Hostinger renewal is missing from the 2025
financial statement and should be recorded.

**Break-even:** about R5,200/month of artist income withdrawn.

## Timeline

Scheduling rule: one agent on a daily cron does one small ticket per day, and dependent tickets run
back to back. Dates are re-baselined as work lands.

### Before 2027-01-01 (Mynger Portfolio Dec 31 Launch Plan, ~9 agent-days)

| Ticket | Work | Days |
|---|---|---|
| 170 | Hide unplayable releases, player feedback, save guard, relink tool | 3 |
| 195 | Move empyrian.net behind Cloudflare (blocked on a Board DNS change) | 1 |
| 224 | GA4 tag | 1 |
| 216 | Brand colours | 1 |
| new | CI running tests against the Docker copy | 2 |
| new | Switch off EDD's "manual" gateway in production | 1 |

### 2027 build (~12 agent-days, done by about 12 January)

| Dates | Work | Days |
|---|---|---|
| Jan 1–3 | Task 171: listener home hub | 3 |
| Jan 4–6 | "Pay with COA" EDD payment method in our plugin | 3 |
| Jan 7–8 | `empyrian/TRACK_PURCHASE` movement and catalogue row | 2 |
| Jan 9–10 | Tips (`empyrian/DONATION`) | 2 |
| Jan 11–12 | Artist earnings view with "Withdraw on BlockBank" | 2 |

Mynger Radio (task 491) already shipped on 2026-10-18 and is live at `/radio`.

WordPress rules apply: our own plugin only (never Musik, Play Block or EDD files), tested on the
local Docker copy on every hook, with a backup and a Hostinger File Manager/SFTP rollback before any
production change. Sales go public with BlockBank's public-money launch (2027-03-01).

## Marketing (cash budget R0)

Recruit independent South African artists to sell directly ("keep 100%"), seed the catalogue first,
and use Mynger Radio on Mynger Home as the discovery channel. No referral codes.

## Risks carried forward

- Music rights and takedowns: a terms clause and a takedown form.
- An empty catalogue: seed content before sales.
- Hostinger's limits under real traffic.
- BlockBank's public-money launch gates sales.
- One agent: any blocked ticket delays every ticket after it.
