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

## Group money rules (Board, 2026-10-26; updated by the owner, 2026-11-16)

1. Every app must be profitable on its own costs, as if it ran alone.
2. Every sale between users pays a **10% platform fee at the point of sale** (owner, 2026-11-15): 9 points are the
   selling app's revenue and 1 point is myWallet's, for running the wallet every sale is paid from. The seller gets
   90% as Mynger credit. Withdrawals are off for good, so nothing is earned "at withdrawal".
3. Every revenue generator has its own entry in OpenBank's money-movement table, so revenue is traceable per app and
   never counted twice.
4. Users pay in Mynger credit, shown in rand (R1 of credit per R1 paid), bought on myWallet (the product formerly
   called BlockBank). Credit is never cash and is never sent between users: it only buys what Mynger lists
   (OpenBank `specs/PAYMENT_LEGAL_REVIEW.md`, section 10).
5. myWallet/OpenBank supports app subscription models.

## Revenue model (approved)

| Generator | Movement | How Empyrian earns |
|---|---|---|
| Track and album sales through EDD | `empyrian/TRACK_PURCHASE` | Platform fee at the point of sale (9 points Empyrian's, 1 myWallet's); artists get 90% as credit |

Tips are dropped (owner, 2026-11-15): a fan who wants to give more can buy the artist's Support on Mynger.
**Platform fee:** 10% of every sale at the point of sale, the app's 9 points and myWallet's 1 (OpenBank
`specs/PLATFORM_FEE.md`, built on `feature/platform-fee`).

How a purchase works: a signed-in listener buys a track priced in rand through EDD's normal cart. At
checkout they choose **Pay with Mynger credit**, a payment method in our own plugin. The plugin asks OpenBank
to sell it as Mynger: the buyer pays in credit and the artist gets 90%, tagged `empyrian/TRACK_PURCHASE`. On
success, EDD completes the
order and unlocks the download. Without enough credit, the buyer gets a "Top up on myWallet" button.

Dropped from the draft: storage tiers (storage belongs to WebDocs).

## The draft's asks (answered)

1. **Profit date:** 2027-12-31. Start: 2027-01-01.
2. **Payments:** myWallet/OpenBank is the rail (group rule 4).
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

**Break-even:** about R5,800/month of track sales (Empyrian's 9%).

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
| Jan 4–6 | "Pay with Mynger credit" EDD payment method in our plugin | 3 |
| Jan 7–8 | `empyrian/TRACK_PURCHASE` movement, catalogue row and OpenBank sale kind | 2 |
| Jan 9–10 | Artist earnings view: credit earned after the platform fee | 2 |

Mynger Radio (task 491) already shipped on 2026-10-18 and is live at `/radio`.

WordPress rules apply: our own plugin only (never Musik, Play Block or EDD files), tested on the
local Docker copy on every hook, with a backup and a Hostinger File Manager/SFTP rollback before any
production change. Sales go public with myWallet's public-money launch (2027-03-01).

## Marketing (cash budget R0)

Recruit independent South African artists to sell directly ("keep 90%"), seed the catalogue first,
and use Mynger Radio on Mynger Home as the discovery channel. No referral codes.

## Risks carried forward

- Music rights and takedowns: a terms clause and a takedown form.
- An empty catalogue: seed content before sales.
- Hostinger's limits under real traffic.
- myWallet's public-money launch gates sales.
- One agent: any blocked ticket delays every ticket after it.
