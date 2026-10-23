# Empyrian.net – Pre-Launch Site Audit Report

**Date:** 12 June 2026  
**Tested by:** Claude (claude.superai@gmail.com)  
**Scope:** Full structural and functional audit across all public-facing pages  
**Platform:** WordPress + Easy Digital Downloads v3.6.6.1 + Mediahub/music theme  
**Testing method:** Structural page analysis via HTTP fetch across all major routes; visual screenshot review; Chrome extension unavailable for interactive session (see note below)

> **Testing note:** The Claude in Chrome extension was not connected during this session, so interactive tests (audio playback initiation, form submission, like/follow button clicks, drag-drop upload) were assessed structurally via page source and DOM inspection rather than live clicking. Items requiring authenticated interaction are flagged separately. Recommend a follow-up interactive session once the extension is connected or tested manually.

---

## Executive Summary

The site has a solid foundation. Content is present, navigation is mostly working, and the music catalogue is substantial. However, **several core pages are empty or broken**, and a number of navigation links are dead stubs (`#`). **Do not launch in 2 days without fixing the critical items.** The most serious issues are the blank Upload, Charts, Genres, and Stations pages, three dead nav links, and the outdated copyright year.

---

## 1. Pages Tested

| Page | URL | Status |
|------|-----|--------|
| Home / Discover | `/` | ✅ Working |
| Sign In / Register | `/login/` | ✅ Working |
| Browse | `/browse/` | ✅ Working |
| Browse > Explore | `/browse/explore/` | ✅ Working |
| Browse > Music | `/browse/audio/` | ✅ Working |
| Browse > Album | `/browse/album/` | ✅ Working (not tested fully) |
| Browse > Playlist | `/browse/playlist/` | Not tested |
| Browse > Artist | `/browse/artist/` | Not tested |
| Browse > Genre | `/browse/genre/` | ✅ Working |
| Artists | `/artists/` | ⚠️ Partial |
| Albums | `/albums/` | ⚠️ Partial |
| Genres (nav link) | `/genres/` | ❌ Broken – empty |
| Charts | `/charts/` | ❌ Broken – empty |
| Stations | `/stations/` | ❌ Broken – 404 |
| Upload | `/upload/` | ❌ Broken – empty (unauthenticated) |
| About | `/about/` | ⚠️ Minor issues |
| Privacy Policy | `/privacy-policy-2/` | ⚠️ Incomplete |
| Individual track page | e.g. `/2025/11/01/klan4eva/` | ✅ Working |
| Individual track page | e.g. `/2025/03/02/wandile-hold-on/` | ✅ Working |
| Genre detail page | `/genre/hip-hop/` | ✅ Working |
| User profile | `/user/claude/` | ⚠️ Empty profile |
| User profile | `/user/administrator/` | Not tested authenticated |

---

## 2. What Works

### Navigation
- Main nav renders correctly with dropdown under Browse
- Browse dropdown sub-items (Explore, Artists, Albums, Genres) are present
- "Sign In" link routes correctly to `/login/`
- "Likes" and "Playlists" correctly redirect unauthenticated users to `/login/` — good gating behaviour

### Login / Registration
- Sign In, Create Account, and Lost Password forms are all on a single page (`/login/`) using URL parameter states (`?action=login`, `?action=register`, `?action=lostpassword`) — clean UX
- Lost password flow is present and structured correctly
- Registration form collects Username, Email, Password with Privacy Policy link

### Browse / Discovery
- Home page "What's New" grid loads with cover art, titles, dates, and links
- Home page "Album & Playlist" section shows albums with covers
- Home page "Users" sidebar and "Genres" sidebar present and linked
- `/browse/` has functioning tabbed sub-nav: Explore | Music | Album | Playlist | Artist | Genre
- `/browse/audio/` and `/browse/explore/` both paginate correctly (11 and 13 pages respectively)
- "Load More" AJAX endpoint present for infinite scroll on genre pages

### Genre Detail Pages
- Genre-specific pages (e.g. `/genre/hip-hop/`) work and show track listings
- Genre sub-nav (sidebar pill links) visible and functional
- "Load More" pagination via REST endpoint: `/wp-json/loop/more?...`

### Individual Track / Album Pages
- Properly show: cover art, title, artist(s), uploader attribution, genre tag, duration, year, play count, like count, comment count
- "More from [Artist]" section shows related content
- "Similar" section shows genre-matched content
- MP3 files are directly hosted on the server (e.g. `wp-content/uploads/2025/11/...mp3`) — confirmed accessible
- Comment form present (WordPress native)
- Breadcrumb navigation present

### About Page
- Mission statement and 50/50 revenue model explained clearly — good copy

### Browse > Genre page (`/browse/genre/`)
- Lists all 9 genres with links — works correctly

---

## 3. What Is Broken or Missing

### 🔴 Critical – Must fix before launch

#### 3.1 Upload Page Is Empty
**URL:** `/upload/`  
**Issue:** The page renders a heading "Upload" and nothing else. There is no upload form, no file picker, no URL input field, and no login prompt. An unauthenticated user gets a blank page with no explanation.  
**Impact:** This is a core feature of the platform. Artists cannot upload music.  
**Fix:** 
- If upload requires login: redirect to `/login/?redirect_to=/upload/` or show a "Please sign in to upload" message with a login button
- If upload form exists but is only visible when logged in: verify the authentication check is working and the form renders post-login
- Confirm the upload flow supports both file upload and URL/link import

#### 3.2 Genres Page Is Empty
**URL:** `/genres/` (linked from main nav "Browse > Genres" dropdown)  
**Issue:** Page renders the "Genres" heading and immediately shows a comment form — zero genre content.  
**Impact:** Confusing dead end for users navigating by genre.  
**Fix:** The working genres page is at `/browse/genre/`. Either:
- Redirect `/genres/` → `/browse/genre/`, or
- Populate `/genres/` with the same genre list (copy the widget/shortcode used on `/browse/genre/`)

#### 3.3 Charts Page Is Empty
**URL:** `/charts/`  
**Issue:** Page renders "Charts" heading with no content at all — no chart data, no placeholder, no "coming soon" message.  
**Impact:** Charts are linked prominently in both the main nav footer and the site footer. Users will hit a dead end.  
**Fix:**
- If charts aren't ready: add a "Coming Soon" placeholder and remove the nav link for now
- If charts should be populated: wire up the play-count data (already tracked per track) to generate a top-tracks ranking

#### 3.4 Stations Page Broken
**URL:** `/stations/`  
**Issue:** Returns "Nothing Found" with a search box — a WordPress 404-style fallback. The page does not exist or is misconfigured.  
**Impact:** Stations is linked in the main nav. Users clicking it get a 404.  
**Fix:**
- If Stations is not built: remove the link from the nav entirely, or replace with "Coming Soon" page
- If Stations should exist: create the page and assign it the correct template

#### 3.5 "My Collection" Nav Link Is Dead
**Location:** Main navigation  
**Issue:** "My Collection" links to `#` — it goes nowhere.  
**Impact:** A logged-in user cannot access their collection from the nav.  
**Fix:** Assign the correct URL to this menu item (likely `/user/{username}/` or a dedicated collection page)

#### 3.6 "Settings" Nav Link Is Dead
**Location:** Main navigation  
**Issue:** "Settings" links to `#` — it goes nowhere.  
**Impact:** Users cannot access account settings.  
**Fix:** Assign to the WordPress profile/edit page or a custom settings page (e.g. `/wp-admin/profile.php` or a front-end settings page)

---

### 🟡 Significant – Fix before launch if possible

#### 3.7 Copyright Year Outdated
**Location:** Site footer, every page  
**Issue:** Footer reads "© Copyright 2024 Empyrian.net" — two years out of date.  
**Fix:** Update to "© 2026 Empyrian.net" or use a dynamic year in the theme template

#### 3.8 Comment Forms on Listing Pages
**URLs:** `/artists/`, `/albums/`  
**Issue:** Both the Artists and Albums listing pages have a standard WordPress comment form at the bottom. This is semantically wrong — you shouldn't be able to "comment on" the artists list page.  
**Fix:** Disable comments on these page types via WordPress Settings > Discussion, or explicitly exclude these templates from showing the comment form

#### 3.9 User Profile Page Is Bare
**URL:** `/user/claude/` (and other users)  
**Issue:** User profiles show only the username, a letter avatar, and a Follow/Following toggle. No bio, no uploaded tracks, no stats, no profile image.  
**Impact:** Artists cannot establish a presence on the platform.  
**Fix:**
- Add profile bio field
- Show the user's uploaded tracks on their profile page
- Enable Gravatar or custom avatar upload
- Add follower/following counts

#### 3.10 "What's New" Section Shows Stale Content
**URL:** Home page `/`  
**Issue:** The "What's New" section on the homepage shows tracks from June 2024 as the primary content, with only one Nov 2025 entry. The most recent content (KLAN4EVA album, Nov 2025) should be featured prominently.  
**Fix:** Ensure the "What's New" widget/query is sorted by `post_date DESC` and pulling the latest posts, not a manually curated static list

#### 3.11 About Page External Image
**URL:** `/about/`  
**Issue:** The about page embeds an image from `http://thefirment.com` — an external, unrelated domain. The image loads over HTTP (not HTTPS), which may trigger mixed-content warnings, and references a domain you don't control.  
**Fix:** Remove or replace the image with an Empyrian-branded asset hosted on empyrian.net

#### 3.12 Footer "Playlist" Link Is Dead
**Location:** Footer nav bar (bottom sticky bar)  
**Issue:** The "Playlist" item in the footer player bar links to `#`.  
**Fix:** Link to `/browse/playlist/` or the user's playlist page when logged in

#### 3.13 Privacy Policy Has Empty Sections
**URL:** `/privacy-policy-2/`  
**Issue:** The "Contact forms" and "Analytics" sections are completely empty — just section headings with no content.  
**Fix:** Either populate these sections or remove the empty headings. An incomplete privacy policy is a legal risk.

#### 3.14 Inconsistent "Genres" Navigation Routing
**Issue:** The main nav "Genres" link (`/genres/`) leads to an empty page, while the correct genre browser is at `/browse/genre/`. These should be consolidated into one working URL.

---

### 🔵 Needs Interactive Verification (Chrome extension / manual)

These items could not be confirmed via structural testing alone:

#### 3.15 Audio Playback
- Cannot confirm the audio player initialises and plays tracks
- Cannot confirm play/pause, seek, volume, and queue controls function
- Cannot confirm the persistent bottom player bar persists across page navigation
- **Test:** Click "Play" on any track and verify audio plays, controls respond, and player persists on navigation

#### 3.16 Upload Flow (When Logged In)
- Cannot confirm whether the upload form appears after login
- Cannot confirm file upload (MP3/WAV) works and attaches correctly to a post
- Cannot confirm URL/link import exists or works
- **Test:** Log in as claude, navigate to `/upload/`, and attempt to upload a file

#### 3.17 Comment Submission
- Comment form is present on track and album pages
- Cannot confirm submission works, whether comments go to moderation queue, or whether logged-in users see a simplified form
- **Test:** Submit a comment on a track page and verify it appears (or goes to moderation)

#### 3.18 Like / Heart Feature
- Like counts are visible on track pages (e.g. "2 likes" shown)
- Cannot confirm the like button works for logged-in users
- Cannot confirm liked tracks appear in the user's `/likes/` page
- **Test:** Log in, click the heart/like icon on a track, check `/likes/`

#### 3.19 Follow / Unfollow Users
- Follow/Following toggle is visible on Artist and User profile pages
- Cannot confirm the AJAX toggle works
- **Test:** Click Follow on an artist, refresh, confirm state persists

#### 3.20 Dark Theme Toggle
- "Dark theme" is in the nav and links to `#theme` — suggests JS-powered
- Cannot confirm it applies and persists across pages
- **Test:** Click Dark Theme toggle and verify visual change and persistence

#### 3.21 Fullscreen Mode
- "Fullscreen" in nav links to `#` — presumably JS
- Cannot confirm this works
- **Test:** Click Fullscreen and verify behaviour

#### 3.22 Search Functionality
- Search box appears on the 404/Stations page
- Cannot confirm a global search works from the main nav
- No visible search icon/field in the main nav was detected
- **Test:** Verify search is accessible and returns relevant results

#### 3.23 Playlists (Logged In)
- `/playlists/` redirects to login — correct gating
- Cannot confirm playlist creation, adding tracks, or playback works
- **Test:** Log in, create a playlist, add tracks, play it

#### 3.24 Music Purchase / Download Flow (EDD)
- Site uses Easy Digital Downloads — implies paid music purchases are supported
- No price tags were visible on the track pages tested
- Cannot confirm checkout flow works
- **Test:** Check if any track has a price set, attempt purchase flow

---

## 4. Content & SEO Issues

| Issue | Detail |
|-------|--------|
| Copyright year | 2024 — update to 2026 |
| Meta descriptions | None observed on any page — add unique meta descriptions for SEO |
| `/genres/` has no canonical | Page is empty so no canonical meta tag is set — fix the page or 301 redirect |
| Inconsistent URL structure | Tracks use date-based WordPress URLs (`/2024/06/20/song-title/`) — consider cleaner `/music/song-title/` slugs |
| `privacy-policy-2` slug | Unprofessional URL slug — rename to `/privacy-policy/` |
| Artist pages have no slug pattern | `/artist/deelow/` (singular) vs `/artists/` (plural) — inconsistent but functional |

---

## 5. Recommendations & Specifications for Future Work

### Priority 1 – Before Launch (Next 2 Days)

1. **Fix or redirect `/genres/`** → redirect to `/browse/genre/` (5 min WordPress menu fix)
2. **Fix or remove `/stations/` nav link** → either create the page or remove it from nav
3. **Fix `/charts/` nav link** → add "Coming Soon" placeholder or remove from nav
4. **Fix "My Collection" and "Settings"** → assign correct URLs in WordPress Appearance > Menus
5. **Fix Upload page** → add login redirect for unauthenticated users or verify the form renders when logged in
6. **Fix footer "Playlist" link** → assign correct URL
7. **Update copyright year** → change to 2026 in theme settings or footer.php
8. **Remove/replace About page external image** → use a hosted asset

### Priority 2 – Polish Before Launch

9. **Fix Artists and Albums pages** → disable comments on listing pages
10. **Complete Privacy Policy** → fill in Contact Forms and Analytics sections
11. **Consolidate Genres nav routing** → one working URL for genres
12. **Improve user profile pages** → add bio, tracks list, avatar support
13. **Verify "What's New" sort order** → ensure newest content appears first

### Priority 3 – Post-Launch Development

14. **Charts** — Build a real-time chart based on play counts. The data is already tracked per track. A simple WP query ordered by a play count meta field would be enough for v1.

15. **Stations** — Define what Stations means for the platform (genre-based radio, curated playlists, user stations?). Build and launch this feature with proper content.

16. **Upload via URL/link** — Currently unclear if this exists. For a platform positioned as "free cloud storage for music," the ability to import via URL (SoundCloud, YouTube, direct MP3 link) would be high-value. Implement using a background media import library.

17. **Upload via file** — Verify the upload form works end-to-end post-login. Consider file size limits, supported formats (MP3, WAV, FLAC, AAC), and error handling for failed uploads.

18. **Artist profiles** — Artist profiles are currently thin. Recommend: bio field, header/banner image, follower count, total plays, discography listing, social links.

19. **Search** — Add a visible global search bar in the header. Current search is only surfaced on error pages. Implement track, artist, and album search with autocomplete.

20. **Comments UX** — The current WordPress comment form is generic. Consider replacing or augmenting with a music-specific interaction (timestamped comments on the waveform, threaded replies). At minimum, ensure comments are moderated before being public.

21. **Responsive / Mobile** — Could not test interactively. Verify the player bar, track listings, and navigation are usable on mobile (`max-scale=1` meta is already set, which is a good sign but needs visual confirmation).

22. **SSL / Mixed Content** — The About page loads an image over HTTP. Audit all media references for mixed content warnings.

23. **Footer cleanup** — Footer "My" link label is truncated. Review all footer labels for clarity (should be "My Library" or "My Music" not just "My").

24. **EDD / Monetisation** — If music sales are a priority, ensure Easy Digital Downloads is properly configured: payment gateways, product pricing on tracks, 50/50 revenue split logic, payout mechanism for artists.

25. **Email notifications** — Verify WordPress email is configured and working (password reset emails, comment notifications, purchase receipts). Test the Lost Password flow by requesting a reset to confirm emails arrive.

---

## 6. Quick Reference: Issue Severity Matrix

| # | Issue | Severity | Effort to Fix |
|---|-------|----------|---------------|
| 3.1 | Upload page empty | 🔴 Critical | Medium |
| 3.2 | /genres/ empty | 🔴 Critical | Low |
| 3.3 | Charts empty | 🔴 Critical | Low (hide) / High (build) |
| 3.4 | Stations broken | 🔴 Critical | Low (hide) |
| 3.5 | My Collection dead link | 🔴 Critical | Low |
| 3.6 | Settings dead link | 🔴 Critical | Low |
| 3.7 | Copyright year 2024 | 🟡 Significant | Trivial |
| 3.8 | Comment forms on listing pages | 🟡 Significant | Low |
| 3.9 | User profiles bare | 🟡 Significant | Medium |
| 3.10 | What's New stale | 🟡 Significant | Low |
| 3.11 | About page external image | 🟡 Significant | Trivial |
| 3.12 | Footer Playlist dead link | 🟡 Significant | Low |
| 3.13 | Privacy Policy incomplete | 🟡 Significant | Low |
| 3.14 | Genres nav inconsistency | 🟡 Significant | Low |
| 3.15–3.24 | Interactive features unverified | 🔵 Needs testing | — |

---

*Report generated: 12 June 2026 | Empyrian.net pre-launch audit*
