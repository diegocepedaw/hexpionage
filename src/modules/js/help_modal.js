/*
 * src/modules/js/help_modal.js — optional split for the Help / Rules modal.
 *
 * Source spec sections:
 *   - UI_SPEC §9 (Help / Rules reference modal — 5 tabs)
 *   - rulebook.md §6 (agent abilities), §9.4 (Honeypot), §9.6 (Blockades),
 *                   §5 (Phases), §8.1 (Win conditions)
 *   - DECISIONS.md D-04, D-05b, D-07, D-17
 *
 * This module exports HEXP_HELP_TABS — a content-only registry consumed by
 * `hexpionage.js::_renderHelpTab`. The main client may inline these strings
 * (current default) OR import this module if A8 prefers a separate file.
 *
 * All copy here is REUSED from rulebook.md per UI_SPEC §9 ("Modal text is
 * reused from rulebook.md via clienttranslate(); no new rules copy is
 * invented here.") No new rules copy is added.
 *
 * Usage pattern (from hexpionage.js):
 *   if (window.HEXP_HELP_TABS) {
 *     // rebuild help host using HEXP_HELP_TABS[tab].title and .body[]
 *   }
 *
 * Each `body` entry is either a plain string (becomes <p>) or an object
 * { kind: "list", ordered?: bool, items: string[] }.
 */

(function () {
  "use strict";

  // I18N-52..57: this file is a static REGISTRY of English source text.
  // Strings are kept unwrapped here (BGA's translator extractor walks JS
  // source and expects to see _() at the source site). The consumer
  // (hexpionage.js::_renderHelpTab) calls _() on every value at render
  // time so the active locale is picked up. To make the strings
  // extractable, a parallel _()-wrapped block lives in _renderHelpTab
  // (the fallback inline content) — that block is what the extractor
  // sees, and these registry literals must match it exactly.
  const HEXP_HELP_TABS = {
    quickref: {
      title: "Agent abilities",
      body: [
        {
          kind: "list",
          items: [
            "Comms Specialist: Move loose intel up one space (1A) or down (1A + 1I). Cannot target intel held by an agent.",
            "Analyst: When retiring with exactly 3 intel, draw 1 bonus tile from the bag and choose to keep (score) or return.",
            "Smuggler: Spend 1 intel to boost your action cap to 4 this turn (once per turn). Or spend 1 intel + 1 action to swap two on-board agents (neither may be pinned).",
            "Engineer: Place a blockade on an adjacent hex (1A) or anywhere on the Field (1I). Max 3 blockades per player on the board.",
            "Hacker: Pin or unpin an adjacent agent (1A; one per Hacker per turn). Steal one intel from any pinned enemy agent (1I; separate slot).",
            "Double Agent: Transfer one of your held intel to ANY agent in play, anywhere on the board (1A). No adjacency required.",
          ],
        },
      ],
    },

    honeypot: {
      title: "Honeypot",
      body: [
        "Gray Honeypots are traps. Any agent that touches one is permanently removed; held intel + the Honeypot return to the bag. (Rulebook §9.4, [D-05b].)",
      ],
    },

    blockade: {
      title: "Blockades",
      body: [
        "A single blockade on a hex redirects intel to the open diagonal. Two blockades on the SW and SE neighbors of a hex stop intel above from trickling — the same applies to Comms vertical moves.",
        "Blockades freeze underlying intel; max 3 of your own active on the board; expire at the end of the opponent's next turn. (Rulebook §9.6, [D-04]/[D-07].)",
      ],
    },

    phases: {
      title: "Phases",
      body: [
        {
          kind: "list",
          ordered: true,
          items: [
            "Trickle — draw 2 intel into entry hexes; roll 6 dice; resolve trickle.",
            "Spawn — up to 3 spawns into ✦ hexes.",
            "Actions — up to 3 (4 with Smuggler boost) actions.",
          ],
        },
      ],
    },

    win: {
      title: "Win conditions",
      body: [
        "First to 20 points wins (rulebook §8.1).",
        "A player with zero pool AND zero on board loses immediately ([D-17]).",
      ],
    },
  };

  // I18N-52..57 (extractor visibility): the BGA translator extractor scans
  // JS source for _() calls. Reference each user-visible string from the
  // registry above through _() so the keys are picked up — the call's
  // return value is intentionally discarded; only the source-side _()
  // marker matters. Guarded so this file remains usable in environments
  // (tests, build pipelines) where _ is not defined.
  if (typeof _ === "function") {
    void _("Agent abilities");
    void _("Comms Specialist: Move loose intel up one space (1A) or down (1A + 1I). Cannot target intel held by an agent.");
    void _("Analyst: When retiring with exactly 3 intel, draw 1 bonus tile from the bag and choose to keep (score) or return.");
    void _("Smuggler: Spend 1 intel to boost your action cap to 4 this turn (once per turn). Or spend 1 intel + 1 action to swap two on-board agents (neither may be pinned).");
    void _("Engineer: Place a blockade on an adjacent hex (1A) or anywhere on the Field (1I). Max 3 blockades per player on the board.");
    void _("Hacker: Pin or unpin an adjacent agent (1A; one per Hacker per turn). Steal one intel from any pinned enemy agent (1I; separate slot).");
    void _("Double Agent: Transfer one of your held intel to ANY agent in play, anywhere on the board (1A). No adjacency required.");
    void _("Honeypot");
    void _("Gray Honeypots are traps. Any agent that touches one is permanently removed; held intel + the Honeypot return to the bag. (Rulebook §9.4, [D-05b].)");
    void _("Blockades");
    void _("A single blockade on a hex redirects intel to the open diagonal. Two blockades on the SW and SE neighbors of a hex stop intel above from trickling — the same applies to Comms vertical moves.");
    void _("Blockades freeze underlying intel; max 3 of your own active on the board; expire at the end of the opponent's next turn. (Rulebook §9.6, [D-04]/[D-07].)");
    void _("Phases");
    void _("Trickle — draw 2 intel into entry hexes; roll 6 dice; resolve trickle.");
    void _("Spawn — up to 3 spawns into ✦ hexes.");
    void _("Actions — up to 3 (4 with Smuggler boost) actions.");
    void _("Win conditions");
    void _("First to 20 points wins (rulebook §8.1).");
    void _("A player with zero pool AND zero on board loses immediately ([D-17]).");
  }

  // Expose globally for hexpionage.js to consume (define()-shimmed envs may
  // also `require(["modules/js/help_modal"])` if loading order permits).
  if (typeof window !== "undefined") {
    window.HEXP_HELP_TABS = HEXP_HELP_TABS;
  }

  // CommonJS fallback (BGA build pipelines may concatenate via webpack).
  if (typeof module !== "undefined" && module.exports) {
    module.exports = HEXP_HELP_TABS;
  }
})();
