/*
 * Game.js — Hexpionage modern BGA framework client (vanilla JS, no jQuery, no Dojo).
 *
 * File location (modern layout): src/modules/js/Game.js
 * Was previously: src/hexpionage.js (legacy layout). Moved per HAL guidance.
 *
 * Source spec sections:
 *   - UI_SPEC §1 (layout), §2 (hex grid), §3 (per-state UI), §3.7b (analyst),
 *               §4 (click behavior), §5 (tooltips), §6 (animations),
 *               §7 (responsive), §8 (spectator), §9 (help), §10 (intro)
 *   - CONTRACT.md §1 (getAllDatas), §2 (notifications 1–24, plus 9/10/10b/10c),
 *                  §3 (sequencing), §4 (hidden-info filtering)
 *   - STATE_MACHINE.md §2 (10 states), §7 (state args), §8 (onEnteringState)
 *   - DECISIONS.md D-19, D-20, D-26, D-15, D-08, D-14, D-05b, D-21, D-18, D-26
 *
 * Critical rules:
 *   - Vanilla JS only. No jQuery, no Dojo.
 *   - No client-side rules logic. Server validates everything.
 *   - All animations: slides ≤300ms, fades ≤200ms, trickle composite ≤1100ms.
 *   - All z-index < 900 (BGA dialogs occupy 950+).
 *   - CSS class prefix `hxp_`.
 *   - Active-player-only UI gated on this.bga.players.getCurrentPlayerId() === this.gamedatas.activeplayer_id.
 *   - Server actions invoked via this.bga.actions.performAction(actionName, args).
 *
 * Note on innerHTML usage: static developer-authored help/intro markup is
 * assigned via innerHTML for readability. All dynamic data flows through
 * textContent or safe attribute assignment to avoid XSS.
 */

/* eslint-disable no-undef */

/* Modern BGA framework: modules/js/Game.js is loaded as an ES module and the
 * framework instantiates `new gameModule.Game(bga)`. The previous Dojo
 * define()/declare() form is the LEGACY shape and is only honoured at the
 * legacy path <gamename>.js, so at this path it exported nothing and the game
 * failed with "gameModule.Game is not a constructor".
 *
 * Note the modern class does NOT extend gamegui: framework helpers come from
 * the injected `bga` object (this.bga.*) instead of `this.*`.
 * dojo remains available as a global, so dojo.subscribe / dojo.string are kept.
 */

  /* ============================================================
   * Static metadata used for label/icon rendering.
   * ============================================================ */

  const AGENT_TYPE_BY_ID = {
    1: "comms_specialist",
    2: "analyst",
    3: "smuggler",
    4: "engineer",
    5: "hacker",
    6: "double_agent",
  };

  const INTEL_TYPE_BY_ID = {
    1: "honeypot",
    2: "industrial_tech",
    3: "leaked_email",
    4: "blackmail",
    5: "security_credential",
    6: "state_secret",
  };

  const INTEL_SCORE_BY_ID = {
    1: 0,  // honeypot — never scored, but score_value field is 0 [D-19]
    2: 2,  // industrial_tech [D-19]
    3: 2,  // leaked_email [D-19]
    4: 2,  // blackmail [D-19]
    5: 3,  // security_credential [D-19]
    6: 4,  // state_secret [D-19]
  };

  const INTEL_DIE_KEYS = [
    "honeypot", "industrial_tech", "leaked_email",
    "blackmail", "security_credential", "state_secret",
  ];

  // I18N-35 / I18N-90: keep snake_case keys for sprite class lookups
  // (CSS class names) but expose translatable English display labels via
  // dedicated helpers. Translation happens at the point of use through _().
  const AGENT_LABEL = {
    comms_specialist: "Comms Specialist",
    analyst: "Analyst",
    smuggler: "Smuggler",
    engineer: "Engineer",
    hacker: "Hacker",
    double_agent: "Double Agent",
  };

  const INTEL_LABEL = {
    honeypot: "Honeypot",
    industrial_tech: "Industrial Tech",
    leaked_email: "Leaked Email",
    blackmail: "Blackmail",
    security_credential: "Security Credential",
    state_secret: "State Secret",
  };

  /* Display-name resolvers (I18N-90) — accept either a numeric type id or a
   * snake_case key and return the English source string ready for _(...).
   */
  const agentTypeDisplayName = (typeIdOrKey) => {
    const key = (typeof typeIdOrKey === "number")
      ? AGENT_TYPE_BY_ID[typeIdOrKey]
      : typeIdOrKey;
    return AGENT_LABEL[key] || "";
  };
  const intelTypeDisplayName = (typeIdOrKey) => {
    const key = (typeof typeIdOrKey === "number")
      ? INTEL_TYPE_BY_ID[typeIdOrKey]
      : typeIdOrKey;
    return INTEL_LABEL[key] || "";
  };

  /* Helper: build a DOM tree from a tag list with safe text. */
  const _h = (tag, attrs, children) => {
    const el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(k => {
        if (k === "className") el.className = attrs[k];
        else if (k === "text") el.textContent = attrs[k];
        else el.setAttribute(k, attrs[k]);
      });
    }
    if (children) {
      (Array.isArray(children) ? children : [children]).forEach(c => {
        if (c == null) return;
        el.appendChild(typeof c === "string" ? document.createTextNode(c) : c);
      });
    }
    return el;
  };

export class Game {

    /* ============================================================
     * Construction
     * ============================================================ */

    constructor(bga) {
      // The modern framework injects its sub-component object (statusBar,
      // players, actions, notifications, gameArea, playerPanels, gameui, ...).
      // Unlike the legacy Dojo class, this class does NOT extend gamegui, so
      // every framework helper is reached through this.bga.*.
      this.bga = bga;

      this._uiState = {
        armedAction: null,
        armedAgentId: null,
        armedExtra: null,
        armedLegalEntry: null,
      };

      this._agentNodes = {};
      this._intelNodes = {};
      this._blockadeNodes = {};
      this._pinNodes = {};
      this._hexNodes = {};
      // FE-02 (S1): per-agent badge map so cleanup doesn't depend on
      // agentNode.parentNode survival or DOM-query selectors.
      this._intelBadgeNodes = {};

      // Hex grid layout constants (UI_SPEC §2.3).
      // FE-13 (S1): defaults are placeholder fallbacks; real values are
      // pulled from CSS custom properties (--hxp-hex-radius / --hxp-origin-x
      // / --hxp-origin-y) in setup() so a single coordinate change only
      // touches CSS.
      this._hex = {
        R: 36,
        originX: 600,
        originY: 304,
      };
    }

    /* Read hex layout from CSS variables — FE-13 (S1). */
    _refreshHexLayoutFromCSS() {
      try {
        const root = document.documentElement;
        const style = getComputedStyle(root);
        const parsePx = (name, fallback) => {
          const raw = (style.getPropertyValue(name) || "").trim();
          if (!raw) return fallback;
          const num = parseFloat(raw);
          return isNaN(num) ? fallback : num;
        };
        const R  = parsePx("--hxp-hex-radius", this._hex.R);
        const ox = parsePx("--hxp-origin-x",   this._hex.originX);
        const oy = parsePx("--hxp-origin-y",   this._hex.originY);
        this._hex.R = R;
        this._hex.originX = ox;
        this._hex.originY = oy;
      } catch (e) {
        // getComputedStyle can throw in headless test envs; keep fallbacks.
      }
    }

    /* ============================================================
     * setup(gamedatas) — BGA_PRIMER §5
     * ============================================================ */

    setup(gamedatas) {
      this.gamedatas = gamedatas;

      // FE-13 (S1): pull canonical hex layout from CSS before laying out.
      this._refreshHexLayoutFromCSS();
      this._setupHexOverlay();
      this._renderPlayerPanels();
      this._renderInitialBoard();
      this._renderDiceTray(gamedatas.dice_state || {});
      this._renderBag(gamedatas.bag_size);
      this._renderScores();
      this._wireStaticHandlers();
      // FE-03 (S2): also kept as a manual call for back-compat; the canonical
      // BGA framework name (without underscore) is the actual method below.
      this.setupNotifications();

      try {
        if (!localStorage.getItem("hxp_intro_seen")) {
          this._showIntroModal();
        }
      } catch (e) { /* localStorage may be blocked; ignore. */ }

      if (window.matchMedia && window.matchMedia("(max-width: 479px)").matches) {
        const banner = document.getElementById("hxp_subphone_banner");
        if (banner) banner.hidden = false;
      }
    }

    /* ============================================================
     * onEnteringState — STATE_MACHINE §2 (10 states)
     * ============================================================ */

    onEnteringState(stateName, args) {
      // FE-10 (S1): cache the live state name so _currentStateName() doesn't
      // read the framework's lagging gamedatas.gamestate.name during
      // transitions (BGA_PRIMER §5).
      this._currentState = stateName;
      const stateArgs = (args && args.args) ? args.args : (args || {});
      this._setPhaseBreadcrumb(stateName);
      this._clearArmed();
      this._clearHighlights();

      switch (stateName) {

        case "gameSetup":
          // §3.1 — splash handled by gameStarted notification animation.
          this._setStatus("");
          break;

        case "trickleDrawLeft":
          this._setSubstate(_("Trickle (drawing 1/2)"));    // I18N-05
          this._setStatus(_("Trickle in progress…"));        // I18N-01
          break;

        case "trickleDrawRight":
          this._setSubstate(_("Trickle (drawing 2/2)"));    // I18N-06
          this._setStatus(_("Trickle in progress…"));        // I18N-02
          break;

        case "trickleRoll":
          this._setSubstate(_("Trickle (rolling)"));         // I18N-07
          this._setStatus(_("Rolling dice…"));               // I18N-03
          break;

        case "trickleResolve":
          this._setSubstate(_("Trickle (resolving)"));       // I18N-08
          this._setStatus(_("Resolving trickle…"));          // I18N-04
          break;

        case "spawn":
          this._setSubstate(_("Spawn — pick a reserve agent, then a ✦ hex.")); // I18N-09
          this._setStatus("");
          if (this.bga.players.isCurrentPlayerActive()) {
            this._renderSpawnAffordances(stateArgs);
          }
          break;

        case "actions":
          // I18N-12 (substate): use a single substituted sentence so the
          // translator sees one complete key.
          this._setSubstate(
            dojo.string.substitute(
              _("Actions — ${remaining} / ${max}"),
              {
                remaining: stateArgs.actions_remaining || 0,
                max: stateArgs.smuggler_boost_used_this_turn ? 4 : 3,
              }
            )
          );
          this._setStatus("");
          this._updateActionCounter(
            stateArgs.actions_remaining,
            stateArgs.smuggler_boost_used_this_turn ? 4 : 3
          );
          // Action buttons rendered by onUpdateActionButtons.
          break;

        case "analystBonusDecision":
          // §3.7b [D-26] — modal driven by private analystBonusDrawn notif.
          if (this.bga.players.isCurrentPlayerActive()) {
            this._setStatus(_("Decide on Analyst bonus…"));  // I18N-10
          } else {
            // I18N-11: substitute the active player's name into a single
            // wrapped sentence (avoids translation-breaking concatenation).
            this._setStatus(
              dojo.string.substitute(
                _("${player_name} is deciding the Analyst bonus…"),
                { player_name: this._getActivePlayerName() }
              )
            );
          }
          break;

        case "endOfTurnCleanup":
          this._setSubstate(_("End of turn — cleaning up."));  // I18N-13
          this._setStatus(_("End of turn — cleaning up."));    // I18N-13
          break;

        case "gameEnd":
          this._setStatus(_("Game over."));                    // I18N-14
          break;

        default:
          this._setStatus("");
          break;
      }
    }

    /* ============================================================
     * onLeavingState
     * ============================================================ */

    onLeavingState(stateName) {
      this._clearArmed();
      this._clearHighlights();
      this._hideModal("hxp_modal_intel_choice");
      this._hideModal("hxp_modal_steal");
      if (stateName === "analystBonusDecision") {
        this._hideModal("hxp_modal_analyst");
      }
    }

    /* ============================================================
     * onUpdateActionButtons — UI_SPEC §3.7.1
     * ============================================================ */

    onUpdateActionButtons(stateName, args) {
      const buttonHost = document.getElementById("hxp_action_buttons");
      if (!buttonHost) return;
      while (buttonHost.firstChild) buttonHost.removeChild(buttonHost.firstChild);

      if (!this.bga.players.isCurrentPlayerActive()) return;

      const stateArgs = args || {};

      switch (stateName) {

        case "spawn":
          // I18N-15..21: every visible button label routed through _().
          this._addBtn(buttonHost, _("Pass Spawn"), "hxp_btn_secondary", () => {
            this.bga.actions.performAction("actPassSpawn");
          });
          // FE-30 (S3): explicit [Cancel] when an agent is armed for spawn.
          if (this._uiState.armedAgentId) {
            this._addBtn(buttonHost, _("Cancel"), "hxp_btn_secondary", () => {
              this._clearArmed();
              this._clearHighlights();
            });
          }
          this._addBtn(buttonHost, "?", "hxp_btn_ghost", () => this._showHelpModal());
          break;

        case "actions": {
          const legal = this._legalActionsByName(stateArgs.legal_actions || []);
          this._addActionBtn(buttonHost, _("Move"),     "actMoveAgent",         legal);
          this._addActionBtn(buttonHost, _("Transfer"), "actTransferIntel",     legal);
          this._addActionBtn(buttonHost, _("Retire"),   "actRetireAgent",       legal);

          this._addDropdown(buttonHost, _("Engineer"), [
            { label: _("Place Adjacent (1A)"), action: "actEngineerPlaceBlockadeAdjacent", legal },
            { label: _("Place Anywhere (1I)"), action: "actEngineerPlaceBlockadeAnywhere", legal },
          ]);
          this._addDropdown(buttonHost, _("Smuggler"), [
            { label: _("Boost (1I)"),          action: "actSmugglerBoostActions", legal },
            { label: _("Swap (1A + 1I)"),      action: "actSmugglerSwapAgents",   legal },
          ]);
          this._addDropdown(buttonHost, _("Comms"), [
            { label: _("Move Up (1A)"),        action: "actCommsMoveIntelUp",   legal },
            { label: _("Move Down (1A + 1I)"), action: "actCommsMoveIntelDown", legal },
          ]);
          // FE-31 (S3): UI_SPEC §3.7.1 order is Double Agent BEFORE Hacker.
          this._addDropdown(buttonHost, _("Double Agent"), [
            { label: _("Transfer To Any (1A)"), action: "actDoubleAgentTransfer", legal },
          ]);
          this._addDropdown(buttonHost, _("Hacker"), [
            { label: _("Pin (1A)"),   action: "actHackerPin",        legal },
            { label: _("Unpin (1A)"), action: "actHackerUnpin",      legal },
            { label: _("Steal (1I)"), action: "actHackerStealIntel", legal },
          ]);

          this._addBtn(buttonHost, _("End Turn"), "hxp_btn_primary", () => {
            this.bga.actions.performAction("actPassActions");
          });
          this._addBtn(buttonHost, "?", "hxp_btn_ghost", () => this._showHelpModal());
          break;
        }

        case "analystBonusDecision":
          // Modal owns the buttons.
          break;

        default:
          break;
      }
    }

    /* ============================================================
     * Action button helpers
     * ============================================================ */

    _addBtn(host, label, cls, onClick, opts) {
      const btn = _h("button", { className: "hxp_btn " + (cls || ""), text: label });
      if (opts && opts.title) btn.title = opts.title;
      if (opts && opts.disabled) btn.disabled = true;
      btn.addEventListener("click", onClick);
      host.appendChild(btn);
      return btn;
    }

    _addActionBtn(host, label, actionName, legal) {
      const isLegal = !!legal[actionName];
      const btn = this._addBtn(host, label, "", () => this._armAction(actionName, legal[actionName]), {
        title: this._tooltipForAction(actionName, isLegal),
      });
      if (!isLegal) btn.classList.add("is-disabled");
      btn.dataset.action = actionName;
      return btn;
    }

    _addDropdown(host, label, items) {
      const allDisabled = items.every(it => !it.legal[it.action]);
      if (allDisabled) return;

      const wrapper = _h("div", { className: "hxp_btn_dropdown" });
      const trigger = _h("button", { className: "hxp_btn", text: label + " ▾" });
      trigger.addEventListener("click", (e) => {
        e.stopPropagation();
        wrapper.classList.toggle("is-open");
      });
      wrapper.appendChild(trigger);

      const menu = _h("div", { className: "hxp_btn_dropdown_menu" });
      items.forEach(it => {
        const isLegal = !!it.legal[it.action];
        const btn = this._addBtn(menu, it.label, "", () => {
          wrapper.classList.remove("is-open");
          this._armAction(it.action, it.legal[it.action]);
        }, { title: this._tooltipForAction(it.action, isLegal) });
        if (!isLegal) btn.classList.add("is-disabled");
        btn.dataset.action = it.action;
      });

      wrapper.appendChild(menu);
      host.appendChild(wrapper);
    }

    _legalActionsByName(legalActionsArr) {
      const out = {};
      (legalActionsArr || []).forEach(entry => { out[entry.name] = entry; });
      return out;
    }

    _tooltipForAction(actionName, isLegal) {
      // I18N-22..23: each tooltip string wrapped with _(). Disabled-suffix
      // is composed via dojo.string.substitute so the translator sees one
      // complete sentence (avoids concatenation anti-pattern).
      const map = {
        actMoveAgent: _("Move an agent to an adjacent Field hex; pick up loose intel on arrival."),
        actTransferIntel: _("Move one intel from one of your agents to an adjacent agent you control."),
        actRetireAgent: _("On a ✦ hex (and not spawned this turn): score all held intel; agent leaves play permanently."),
        actEngineerPlaceBlockadeAdjacent: _("Engineer places a blockade on an adjacent empty Field hex."),
        actEngineerPlaceBlockadeAnywhere: _("Engineer spends one of its intel; place a blockade anywhere in the Field."),
        actSmugglerBoostActions: _("Spend 1 intel; raise your action cap to 4 this turn (once per turn)."),
        actSmugglerSwapAgents: _("Spend 1 intel; swap any two on-board agents (neither may be pinned)."),
        actCommsMoveIntelUp: _("Move one loose intel one hex up (NW or NE)."),
        actCommsMoveIntelDown: _("Spend 1 intel; move one loose intel one hex down (SW or SE)."),
        actDoubleAgentTransfer: _("Send one of this Double Agent's intel to ANY other agent in play."),
        actHackerPin: _("Pin an adjacent enemy agent."),
        actHackerUnpin: _("Unpin an adjacent friendly pinned agent."),
        actHackerStealIntel: _("Steal one intel from any pinned enemy agent (anywhere on the board)."),
      };
      const base = map[actionName] || "";
      if (isLegal) return base;
      return dojo.string.substitute(
        _("${base} (Currently disabled.)"),
        { base: base }
      );
    }

    /* ============================================================
     * Action arming flow — UI_SPEC §3.7.2
     * ============================================================ */

    _armAction(actionName, legalEntry) {
      this._clearArmed();
      this._uiState.armedAction = actionName;
      this._uiState.armedAgentId = null;
      this._uiState.armedLegalEntry = legalEntry;

      document.querySelectorAll(".hxp_btn[data-action]").forEach(b => {
        b.classList.toggle("is-armed", b.dataset.action === actionName);
      });

      this._highlightLegalSources(actionName, legalEntry);
      this._setStatus(_("Pick a source agent or target."));    // I18N-24
    }

    _clearArmed() {
      this._uiState.armedAction = null;
      this._uiState.armedAgentId = null;
      this._uiState.armedLegalEntry = null;
      this._uiState.armedExtra = null;
      document.querySelectorAll(".hxp_btn.is-armed").forEach(b => b.classList.remove("is-armed"));
      // FE-24 (S2): also reset the gold outline on reserve cells; otherwise
      // Escape leaves the cell visually armed.
      document.querySelectorAll(".hxp_reserve_cell.is-armed").forEach(c => c.classList.remove("is-armed"));
    }

    _highlightLegalSources(actionName, entry) {
      this._clearHighlights();
      if (!entry) return;

      const sources = entry.agents || entry.smugglers || entry.engineers ||
                      entry.hackers || entry.double_agents || [];
      sources.forEach(src => {
        const id = src.agent_id || src.smuggler_id || src.engineer_id || src.hacker_id;
        if (id && this._agentNodes[id]) {
          this._agentNodes[id].classList.add("is-legal");
        }
      });

      if (actionName === "actCommsMoveIntelUp" || actionName === "actCommsMoveIntelDown") {
        (entry.moves || []).forEach(m => {
          if (this._intelNodes[m.intel_id]) {
            this._intelNodes[m.intel_id].classList.add("is-legal");
          }
        });
      }
    }

    _clearHighlights() {
      document.querySelectorAll(".hxp_hex.is-legal,.hxp_hex.is-armed-source,.hxp_hex.is-target-preview")
        .forEach(n => n.classList.remove("is-legal", "is-armed-source", "is-target-preview"));
      document.querySelectorAll(".hxp_agent.is-legal,.hxp_agent.is-armed-source")
        .forEach(n => n.classList.remove("is-legal", "is-armed-source"));
      document.querySelectorAll(".hxp_intel.is-legal")
        .forEach(n => n.classList.remove("is-legal"));
    }

    /* ============================================================
     * Spawn UI — UI_SPEC §3.6
     * ============================================================ */

    _renderSpawnAffordances(args) {
      (args.available_spawn_hexes || []).forEach(hx => {
        const node = this._hexNode(hx.q, hx.r);
        if (node) node.classList.add("is-legal");
      });
      const reserveCells = document.querySelectorAll(
        "#hxp_player_panel_left .hxp_reserve_cell, #hxp_player_panel_right .hxp_reserve_cell"
      );
      reserveCells.forEach(cell => cell.classList.remove("is-armed"));

      // FE-23 (S2): tag each reserve cell as legal/disabled per
      // available_agents_in_pool so the visual affordance matches the rules.
      const available = (args.available_agents_in_pool || []).reduce((acc, x) => {
        acc[String(x.agent_id)] = true;
        return acc;
      }, {});
      const ownPanel = document.querySelector(
        '#hxp_player_panel_left[class*="is-active"] .hxp_reserve_grid, ' +
        '#hxp_player_panel_left.is-active .hxp_reserve_grid, ' +
        '#hxp_player_panel_right.is-active .hxp_reserve_grid'
      ) || document.querySelector('.hxp_player_panel.is-active .hxp_reserve_grid');
      if (ownPanel) {
        ownPanel.querySelectorAll(".hxp_reserve_cell").forEach(cell => {
          cell.classList.remove("is-legal", "is-disabled");
          if (cell.classList.contains("is-spent")) return;
          const id = cell.dataset.agentId;
          if (id && available[id]) cell.classList.add("is-legal");
          else cell.classList.add("is-disabled");
        });
      }
    }

    /* ============================================================
     * Hex grid (UI_SPEC §2)
     * ============================================================ */

    _setupHexOverlay() {
      // FE-12 (S0): backend now ships board_layout with separate field_hexes,
      // orange_hexes, spawn_row_hexes, and intel_entry_top_left/right anchors.
      // Render every clickable hex (Field + Orange) with the correct CSS class
      // and tag spawn-row + entry-row hexes additively. Without this, the
      // entire overlay was empty and the board was unclickable.
      const overlay = document.getElementById("hxp_hex_overlay");
      if (!overlay || !this.gamedatas) return;
      while (overlay.firstChild) overlay.removeChild(overlay.firstChild);

      const layout = this.gamedatas.board_layout || {};
      const fieldHexes  = layout.field_hexes  || [];
      const orangeHexes = layout.orange_hexes || [];
      const spawnRow    = layout.spawn_row_hexes || [];
      const entryTL     = layout.intel_entry_top_left  || null;
      const entryTR     = layout.intel_entry_top_right || null;

      // Build a quick lookup for the additive markers.
      const spawnSet = new Set(spawnRow.map(h => h.q + "," + h.r));
      const entryLeftKey  = entryTL ? (entryTL.q + "," + entryTL.r) : null;
      const entryRightKey = entryTR ? (entryTR.q + "," + entryTR.r) : null;

      const renderHex = (hx, baseClass) => {
        const node = _h("div", { className: "hxp_hex " + baseClass });
        node.dataset.q = hx.q;
        node.dataset.r = hx.r;
        const key = hx.q + "," + hx.r;
        if (spawnSet.has(key))         node.classList.add("hxp_hex_spawn");
        if (key === entryLeftKey)      node.classList.add("hxp_hex_entry", "hxp_hex_entry_l");
        if (key === entryRightKey)     node.classList.add("hxp_hex_entry", "hxp_hex_entry_r");
        const px = this.hexToPixel(hx.q, hx.r);
        node.style.left = px.x + "px";
        node.style.top  = px.y + "px";
        node.addEventListener("click", () => this._onHexClick(hx.q, hx.r));
        overlay.appendChild(node);
        this._hexNodes[key] = node;
      };

      fieldHexes.forEach(hx => renderHex(hx, "hxp_hex_field"));
      orangeHexes.forEach(hx => renderHex(hx, "hxp_hex_orange"));
    }

    _hexNode(q, r) { return this._hexNodes[q + "," + r] || null; }

    /**
     * Pointy-top axial → pixel transform per UI_SPEC §2.3.
     */
    hexToPixel(q, r) {
      const R = this._hex.R;
      const W = Math.sqrt(3) * R;
      const H = 2 * R;
      const x = this._hex.originX + W * (q + r / 2);
      const y = this._hex.originY + (3 * R / 2) * r;
      return { x: x, y: y, w: W, h: H };
    }

    /**
     * Inverse transform with Red Blob cube round.
     */
    pixelToHex(px, py) {
      const R = this._hex.R;
      const x = px - this._hex.originX;
      const y = py - this._hex.originY;
      const q = (Math.sqrt(3) / 3 * x - 1 / 3 * y) / R;
      const r = (2 / 3 * y) / R;
      return this._cubeRound(q, r);
    }

    _cubeRound(q, r) {
      const x = q;
      const z = r;
      const y = -x - z;
      let rx = Math.round(x), ry = Math.round(y), rz = Math.round(z);
      const xd = Math.abs(rx - x), yd = Math.abs(ry - y), zd = Math.abs(rz - z);
      if (xd > yd && xd > zd) rx = -ry - rz;
      else if (yd > zd)        ry = -rx - rz;
      else                     rz = -rx - ry;
      return { q: rx, r: rz };
    }

    _onHexClick(q, r) {
      if (!this.bga.players.isCurrentPlayerActive()) return;
      const action = this._uiState.armedAction;

      if (this._currentStateName() === "spawn") {
        if (this._uiState.armedAgentId !== null) {
          this.bga.actions.performAction("actSpawnAgent", {
            agent_id: this._uiState.armedAgentId, q: q, r: r,
          });
          this._clearArmed();
          this._clearHighlights();
        } else {
          // FE-26 (S2): give the user feedback rather than silently no-op'ing.
          this._setStatus(_("Pick an agent first."));
        }
        return;
      }

      if (!action) {
        // FE-26 (S2): same feedback in actions phase.
        this._setStatus(_("Pick an action first."));
        return;
      }

      switch (action) {
        case "actMoveAgent":
          if (this._uiState.armedAgentId) {
            this.bga.actions.performAction("actMoveAgent", {
              agent_id: this._uiState.armedAgentId, q: q, r: r,
            });
            this._clearArmed();
          }
          break;
        case "actEngineerPlaceBlockadeAdjacent":
          if (this._uiState.armedAgentId) {
            this.bga.actions.performAction("actEngineerPlaceBlockadeAdjacent", {
              engineer_id: this._uiState.armedAgentId, q: q, r: r,
            });
            this._clearArmed();
          }
          break;
        case "actEngineerPlaceBlockadeAnywhere":
          if (this._uiState.armedAgentId) {
            const intelOpts = this._uiState.armedExtra || [];
            this._pickIntelThen(intelOpts, (intelId) => {
              this.bga.actions.performAction("actEngineerPlaceBlockadeAnywhere", {
                engineer_id: this._uiState.armedAgentId, q: q, r: r, intel_id: intelId,
              });
              this._clearArmed();
            });
          }
          break;
        case "actCommsMoveIntelUp":
          if (this._uiState.armedExtra && this._uiState.armedExtra.intel_id) {
            this.bga.actions.performAction("actCommsMoveIntelUp", {
              comms_id: this._uiState.armedExtra.comms_agent_id,
              intel_id: this._uiState.armedExtra.intel_id, q: q, r: r,
            });
            this._clearArmed();
          }
          break;
        case "actCommsMoveIntelDown":
          if (this._uiState.armedExtra && this._uiState.armedExtra.intel_id) {
            const intelOpts = this._uiState.armedExtra.intel_paid_options || [];
            const intelId = this._uiState.armedExtra.intel_id;
            const commsId = this._uiState.armedExtra.comms_agent_id;
            const targetQ = q, targetR = r;
            this._pickIntelThen(intelOpts.filter(id => id !== intelId), (paid) => {
              this.bga.actions.performAction("actCommsMoveIntelDown", {
                target_intel_id: intelId, q: targetQ, r: targetR,
                comms_id: commsId, paid_intel_id: paid,
              });
              this._clearArmed();
            });
          }
          break;
        default:
          break;
      }
    }

    /* ============================================================
     * Agent / intel / blockade rendering helpers
     * ============================================================ */

    _renderInitialBoard() {
      ["hxp_blockade_layer", "hxp_intel_layer", "hxp_agent_layer", "hxp_pin_layer"].forEach(id => {
        const n = document.getElementById(id);
        if (n) while (n.firstChild) n.removeChild(n.firstChild);
      });

      (this.gamedatas.agents || []).forEach(agent => {
        if (agent.state === 1 && agent.hex) this._spawnAgentNode(agent);
      });

      (this.gamedatas.intel_on_board || []).forEach(tile => this._placeIntelNode(tile));

      (this.gamedatas.blockades || []).forEach(b => this._placeBlockadeNode(b));

      (this.gamedatas.agents || []).forEach(agent => {
        if (agent.state === 1 && agent.intel_held && agent.intel_held.length) {
          this._renderHeldIntelBadges(agent);
        }
      });

      (this.gamedatas.agents || []).forEach(agent => {
        if (agent.pinned_until_turn) this._renderPinMarker(agent);
      });
    }

    _spawnAgentNode(agent) {
      const layer = document.getElementById("hxp_agent_layer");
      if (!layer) return;
      const colorClass = (agent.owner === this._whitePlayerId() ? "hxp_agent_white" : "hxp_agent_black");
      const typeClass  = "hxp_agent_" + AGENT_TYPE_BY_ID[agent.type];
      const node = _h("div", { className: "hxp_agent " + typeClass + " " + colorClass });
      node.dataset.agentId = agent.id;
      const px = this.hexToPixel(agent.hex.q, agent.hex.r);
      node.style.left = px.x + "px";
      node.style.top  = px.y + "px";
      // I18N-35: translate the agent type name at point of use.
      node.title = _(agentTypeDisplayName(agent.type));
      node.addEventListener("click", () => this._onAgentClick(agent.id));
      layer.appendChild(node);
      this._agentNodes[agent.id] = node;
      return node;
    }

    _placeIntelNode(tile) {
      const layer = document.getElementById("hxp_intel_layer");
      if (!layer) return;
      const typeClass = "hxp_intel_" + INTEL_TYPE_BY_ID[tile.type];
      const node = _h("div", { className: "hxp_intel " + typeClass + " hxp_intel_face" });
      node.dataset.intelId = tile.id;
      const px = this.hexToPixel(tile.hex.q, tile.hex.r);
      node.style.left = px.x + "px";
      node.style.top  = px.y + "px";
      const stackOff = (tile.stack_order || 0) * 4;
      node.style.transform = "translate(" + stackOff + "px," + (-stackOff) + "px)";
      // I18N-35: substituted single-key sentence so translator sees one
      // complete tooltip ("${name} (+${score})").
      node.title = dojo.string.substitute(
        _("${name} (+${score})"),
        {
          name: _(intelTypeDisplayName(tile.type)),
          score: INTEL_SCORE_BY_ID[tile.type] || 0,
        }
      );
      node.addEventListener("click", () => this._onIntelClick(tile.id));
      layer.appendChild(node);
      this._intelNodes[tile.id] = node;
    }

    _placeBlockadeNode(b) {
      const layer = document.getElementById("hxp_blockade_layer");
      if (!layer) return;
      const colorClass = (b.owner === this._whitePlayerId() ? "hxp_token_white" : "hxp_token_black");
      const node = _h("div", { className: "hxp_blockade " + colorClass });
      node.dataset.blockadeId = b.id;
      const px = this.hexToPixel(b.hex.q, b.hex.r);
      node.style.left = px.x + "px";
      node.style.top  = px.y + "px";
      layer.appendChild(node);
      this._blockadeNodes[b.id] = node;
    }

    _renderHeldIntelBadges(agent) {
      // FE-02 (S1): use a dedicated per-agent badge map for cleanup so we
      // don't depend on parentNode + DOM-query selectors (which leak
      // orphans across re-parenting).
      const agentNode = this._agentNodes[agent.id];
      if (!agentNode) return;
      const prev = this._intelBadgeNodes[agent.id] || [];
      prev.forEach(n => { if (n && n.parentNode) n.parentNode.removeChild(n); });
      this._intelBadgeNodes[agent.id] = [];

      // Defense-in-depth: also sweep any stragglers matching the dataset
      // attribute under the agent layer.
      const layer = document.getElementById("hxp_agent_layer");
      if (layer) {
        layer.querySelectorAll(
          '.hxp_intel_badge[data-agent-id="' + agent.id + '"]'
        ).forEach(n => n.remove());
      }

      const px = this.hexToPixel(agent.hex.q, agent.hex.r);
      (agent.intel_held || []).forEach((intelId, idx) => {
        const intelInfo = (this.gamedatas.intel_revealed || []).find(t => t.id === intelId);
        if (!intelInfo) return;
        const badge = _h("div", { className: "hxp_intel_badge hxp_intel_" + INTEL_TYPE_BY_ID[intelInfo.type] });
        badge.dataset.agentId = agent.id;
        badge.dataset.intelId = intelId;
        badge.style.left = (px.x + 16 + idx * 12) + "px";
        badge.style.top  = (px.y + 18) + "px";
        const host = (agentNode.parentNode) || layer;
        if (host) host.appendChild(badge);
        this._intelBadgeNodes[agent.id].push(badge);
      });
    }

    _renderPinMarker(agent) {
      const layer = document.getElementById("hxp_pin_layer");
      if (!layer) return;
      const opponentColor = (agent.owner === this._whitePlayerId() ? "hxp_token_black" : "hxp_token_white");
      const node = _h("div", { className: "hxp_pin_marker hxp_token hxp_token_pin " + opponentColor });
      node.dataset.agentId = agent.id;
      const px = this.hexToPixel(agent.hex.q, agent.hex.r);
      node.style.left = px.x + "px";
      node.style.top  = px.y + "px";
      layer.appendChild(node);
      this._pinNodes[agent.id] = node;
      const agentNode = this._agentNodes[agent.id];
      if (agentNode) agentNode.classList.add("is-pinned");
    }

    _whitePlayerId() {
      // FE-11 (S1): BGA player ordering comes from player_no (1 or 2), NOT
      // numeric player_id. White = player_no=1, Black = player_no=2 per
      // STATE_MODEL §4.1 / BGA_PRIMER §6. Sorting by id silently swaps colors
      // when the two ids don't sort the same way as the seating order.
      const players = this.gamedatas.players || {};
      const ids = Object.keys(players);
      for (let i = 0; i < ids.length; i++) {
        const p = players[ids[i]];
        if (p && Number(p.player_no) === 1) return Number(ids[i]);
      }
      // Fallback: first id in the players map.
      return ids.length ? Number(ids[0]) : null;
    }

    _onAgentClick(agentId) {
      if (!this.bga.players.isCurrentPlayerActive()) return;
      const action = this._uiState.armedAction;
      if (!action) return;

      const entry = this._uiState.armedLegalEntry || {};

      const setSource = () => {
        this._uiState.armedAgentId = agentId;
        const node = this._agentNodes[agentId];
        if (node) node.classList.add("is-armed-source");
        this._highlightLegalTargetsForAgent(action, agentId, entry);
      };

      switch (action) {
        case "actMoveAgent":
        case "actEngineerPlaceBlockadeAdjacent":
        case "actEngineerPlaceBlockadeAnywhere":
        case "actSmugglerBoostActions":
          if (!this._uiState.armedAgentId) {
            setSource();
            if (action === "actSmugglerBoostActions") {
              const opts = this._intelOptionsFor(entry, agentId) || [];
              this._pickIntelThen(opts, (intelId) => {
                this.bga.actions.performAction("actSmugglerBoostActions", {
                  smuggler_id: agentId, intel_id: intelId,
                });
                this._clearArmed();
              });
            }
          }
          break;

        case "actHackerPin":
        case "actHackerUnpin":
          if (!this._uiState.armedAgentId) {
            setSource();
          } else {
            this.bga.actions.performAction(action, {
              hacker_id: this._uiState.armedAgentId,
              target_agent_id: agentId,
            });
            this._clearArmed();
          }
          break;

        case "actTransferIntel":
          if (!this._uiState.armedAgentId) {
            setSource();
          } else {
            const transferEntry = (entry.transfers || []).find(t =>
              t.source_agent_id === this._uiState.armedAgentId &&
              t.target_agent_id === agentId
            );
            const intelOpts = (transferEntry && transferEntry.transferable_intel_ids) || [];
            this._pickIntelThen(intelOpts, (intelId) => {
              this.bga.actions.performAction("actTransferIntel", {
                source_agent_id: this._uiState.armedAgentId,
                target_agent_id: agentId,
                intel_id: intelId,
              });
              this._clearArmed();
            });
          }
          break;

        case "actRetireAgent":
          // FREE per [D-14]; payload is just {agent_id} per [D-26].
          this.bga.actions.performAction("actRetireAgent", { agent_id: agentId });
          this._clearArmed();
          break;

        case "actSmugglerSwapAgents":
          if (!this._uiState.armedAgentId) {
            setSource();
            this._uiState.armedExtra = { stage: "pick_a" };
          } else if (this._uiState.armedExtra && this._uiState.armedExtra.stage === "pick_a") {
            this._uiState.armedExtra = {
              stage: "pick_b",
              agent_a_id: agentId,
              smuggler_id: this._uiState.armedAgentId,
            };
            this._setStatus(_("Pick the second agent to swap."));    // I18N-25
          } else if (this._uiState.armedExtra && this._uiState.armedExtra.stage === "pick_b") {
            const ctx = this._uiState.armedExtra;
            const opts = this._intelOptionsFor(entry, ctx.smuggler_id) || [];
            this._pickIntelThen(opts, (intelId) => {
              this.bga.actions.performAction("actSmugglerSwapAgents", {
                smuggler_id: ctx.smuggler_id,
                agent_a_id: ctx.agent_a_id,
                agent_b_id: agentId,
                intel_id: intelId,
              });
              this._clearArmed();
            });
          }
          break;

        case "actDoubleAgentTransfer":
          if (!this._uiState.armedAgentId) {
            setSource();
          } else {
            const da = (entry.double_agents || []).find(d => d.agent_id === this._uiState.armedAgentId);
            const intelOpts = (da && da.transferable_intel_ids) || [];
            this._pickIntelThen(intelOpts, (intelId) => {
              this.bga.actions.performAction("actDoubleAgentTransfer", {
                double_agent_id: this._uiState.armedAgentId,
                target_agent_id: agentId,
                intel_id: intelId,
              });
              this._clearArmed();
            });
          }
          break;

        case "actHackerStealIntel":
          this._openStealWizard(entry, agentId);
          break;

        default:
          break;
      }
    }

    _highlightLegalTargetsForAgent(action, agentId, entry) {
      this._clearHighlights();
      const src = this._agentNodes[agentId];
      if (src) src.classList.add("is-armed-source");

      switch (action) {
        case "actMoveAgent": {
          const a = (entry.agents || []).find(x => x.agent_id === agentId);
          (a ? a.legal_targets : []).forEach(hx => {
            const n = this._hexNode(hx.q, hx.r);
            if (n) n.classList.add("is-legal");
          });
          break;
        }
        case "actEngineerPlaceBlockadeAdjacent":
        case "actEngineerPlaceBlockadeAnywhere": {
          const e = (entry.engineers || []).find(x => x.agent_id === agentId);
          ((e && e.legal_target_hexes) || []).forEach(hx => {
            const n = this._hexNode(hx.q, hx.r);
            if (n) n.classList.add("is-legal");
          });
          if (action === "actEngineerPlaceBlockadeAnywhere") {
            this._uiState.armedExtra = (e && e.intel_paid_options) || [];
          }
          break;
        }
        case "actTransferIntel": {
          const targets = (entry.transfers || [])
            .filter(t => t.source_agent_id === agentId)
            .map(t => t.target_agent_id);
          targets.forEach(id => {
            if (this._agentNodes[id]) this._agentNodes[id].classList.add("is-legal");
          });
          break;
        }
        case "actDoubleAgentTransfer": {
          const da = (entry.double_agents || []).find(x => x.agent_id === agentId);
          ((da && da.legal_target_agents) || []).forEach(id => {
            if (this._agentNodes[id]) this._agentNodes[id].classList.add("is-legal");
          });
          break;
        }
        case "actSmugglerSwapAgents":
          (entry.smugglers || []).forEach(s => {
            (s.legal_pairs || []).forEach(pair => {
              pair.forEach(id => {
                if (this._agentNodes[id] && id !== agentId) {
                  this._agentNodes[id].classList.add("is-legal");
                }
              });
            });
          });
          break;
        case "actHackerPin":
        case "actHackerUnpin": {
          const h = (entry.hackers || []).find(x => x.agent_id === agentId);
          ((h && h.legal_target_agents) || []).forEach(id => {
            if (this._agentNodes[id]) this._agentNodes[id].classList.add("is-legal");
          });
          break;
        }
        default:
          break;
      }
    }

    _intelOptionsFor(entry, agentId) {
      const groups = ["smugglers", "engineers", "hackers", "double_agents"];
      for (let i = 0; i < groups.length; i++) {
        const arr = entry[groups[i]] || [];
        const m = arr.find(x => x.agent_id === agentId || x.smuggler_id === agentId);
        if (m && m.intel_paid_options) return m.intel_paid_options;
      }
      return [];
    }

    _onIntelClick(intelId) {
      if (!this.bga.players.isCurrentPlayerActive()) return;
      const action = this._uiState.armedAction;
      if (action !== "actCommsMoveIntelUp" && action !== "actCommsMoveIntelDown") return;
      const entry = this._uiState.armedLegalEntry || {};
      const move = (entry.moves || []).find(m => m.intel_id === intelId);
      if (!move) return;

      this._uiState.armedExtra = move;

      this._clearHighlights();
      const intelNode = this._intelNodes[intelId];
      if (intelNode) intelNode.classList.add("is-armed-source");
      (move.legal_targets || []).forEach(hx => {
        const n = this._hexNode(hx.q, hx.r);
        if (n) n.classList.add("is-legal");
      });
      this._setStatus(_("Pick the destination hex (NW/NE for Up, SW/SE for Down).")); // I18N-26
    }

    /* ============================================================
     * Intel choice modal — UI_SPEC §4
     * ============================================================ */

    _pickIntelThen(intelIds, callback) {
      if (!intelIds || intelIds.length <= 1) {
        callback(intelIds && intelIds.length === 1 ? intelIds[0] : null);
        return;
      }
      const title = document.getElementById("hxp_modal_intel_title");
      const body  = document.getElementById("hxp_modal_intel_choices");
      title.textContent = _("Choose an intel tile");                 // I18N-27
      while (body.firstChild) body.removeChild(body.firstChild);
      intelIds.forEach(id => {
        const info = (this.gamedatas.intel_revealed || []).find(t => t.id === id);
        let labelText;
        if (info) {
          // I18N-28: substituted, single-key label so translator sees the
          // whole sentence, not concatenated fragments.
          labelText = dojo.string.substitute(
            _("${name} (+${score})"),
            {
              name: _(intelTypeDisplayName(info.type)),
              score: info.score_value || 0,
            }
          );
        } else {
          labelText = dojo.string.substitute(_("Intel #${id}"), { id: id });
        }
        const btn = _h("button", { className: "hxp_btn", text: labelText });
        btn.addEventListener("click", () => {
          this._hideModal("hxp_modal_intel_choice");
          callback(id);
        });
        body.appendChild(btn);
      });
      this._showModal("hxp_modal_intel_choice");
      const cancelBtn = document.getElementById("hxp_btn_intel_cancel");
      if (cancelBtn) cancelBtn.onclick = () => {
        this._hideModal("hxp_modal_intel_choice");
        this._clearArmed();
      };
    }

    /* ============================================================
     * Hacker steal wizard — UI_SPEC §4.6
     * ============================================================ */

    _openStealWizard(entry, victimAgentId) {
      const title = document.getElementById("hxp_modal_steal_title");
      const body  = document.getElementById("hxp_modal_steal_body");

      const hackers = entry.hackers || [];
      let chosenHacker = hackers.length === 1 ? hackers[0] : null;
      // FE-28 (S2): track the wizard step so Back returns step-by-step.
      let currentStep = 1;
      let lastStolenId = null;

      const clearBody = () => { while (body.firstChild) body.removeChild(body.firstChild); };

      const renderStep1 = () => {
        currentStep = 1;
        title.textContent = _("Pick a Hacker");                  // I18N-28a
        clearBody();
        hackers.forEach(h => {
          // I18N-29: build "Hacker #N" via single-substitution key.
          const btn = _h("button", {
            className: "hxp_btn",
            text: dojo.string.substitute(_("Hacker #${id}"), { id: h.agent_id }),
          });
          btn.addEventListener("click", () => { chosenHacker = h; renderStep2(); });
          body.appendChild(btn);
        });
      };

      const renderStep2 = () => {
        currentStep = 2;
        const target = (chosenHacker.legal_targets || []).find(t => t.target_agent_id === victimAgentId);
        const stealable = (target && target.stealable_intel_ids) || [];
        title.textContent = _("Pick intel to steal");            // I18N-30
        clearBody();
        stealable.forEach(id => {
          const info = (this.gamedatas.intel_revealed || []).find(t => t.id === id);
          const labelText = info
            ? _(intelTypeDisplayName(info.type))
            : dojo.string.substitute(_("Intel #${id}"), { id: id });
          const btn = _h("button", { className: "hxp_btn", text: labelText });
          btn.addEventListener("click", () => renderStep3(id));
          body.appendChild(btn);
        });
      };

      const renderStep3 = (stolenId) => {
        currentStep = 3;
        lastStolenId = stolenId;
        title.textContent = _("Pay 1 intel from this Hacker");    // I18N-31
        clearBody();
        (chosenHacker.intel_paid_options || []).forEach(id => {
          const info = (this.gamedatas.intel_revealed || []).find(t => t.id === id);
          const labelText = info
            ? _(intelTypeDisplayName(info.type))
            : dojo.string.substitute(_("Intel #${id}"), { id: id });
          const btn = _h("button", { className: "hxp_btn", text: labelText });
          btn.addEventListener("click", () => {
            this._hideModal("hxp_modal_steal");
            this.bga.actions.performAction("actHackerStealIntel", {
              hacker_id: chosenHacker.agent_id,
              target_agent_id: victimAgentId,
              stolen_intel_id: stolenId,
              paid_intel_id: id,
            });
            this._clearArmed();
          });
          body.appendChild(btn);
        });
      };

      this._showModal("hxp_modal_steal");
      if (chosenHacker) renderStep2(); else renderStep1();

      const cancelBtn = document.getElementById("hxp_btn_steal_cancel");
      if (cancelBtn) cancelBtn.onclick = () => { this._hideModal("hxp_modal_steal"); this._clearArmed(); };
      const backBtn = document.getElementById("hxp_btn_steal_back");
      if (backBtn) backBtn.onclick = () => {
        // FE-28 (S2): step back by one, not to step 1.
        if (currentStep === 3) {
          renderStep2();
        } else if (currentStep === 2 && hackers.length > 1) {
          chosenHacker = null;
          renderStep1();
        }
      };
    }

    /* ============================================================
     * Player panels & dice tray
     * ============================================================ */

    _renderPlayerPanels() {
      // FE-11 (S1): Assign sides via "self = left" (active viewer always sees
      // their own panel on the left), with the opponent on the right.
      // Spectator: fall back to player_no ordering.
      const players = this.gamedatas.players || {};
      const allIds = Object.keys(players).map(Number);
      const selfId = Number(this.bga.players.getCurrentPlayerId());
      let leftId, rightId;
      if (selfId && players[selfId]) {
        leftId = selfId;
        rightId = allIds.find(id => id !== selfId);
      } else {
        const sortedByNo = allIds.slice().sort((a, b) => {
          const na = Number((players[a] || {}).player_no || 0);
          const nb = Number((players[b] || {}).player_no || 0);
          return na - nb;
        });
        leftId  = sortedByNo[0];
        rightId = sortedByNo[1];
      }
      const sideMap = { left: leftId, right: rightId };
      ["left", "right"].forEach((side) => {
        const id = sideMap[side];
        if (!id) return;
        const p = players[id];
        const panel = document.querySelector('[data-side="' + side + '"]');
        if (!panel) return;
        const nameEl = panel.querySelector(".hxp_panel_player_name");
        if (nameEl) {
          nameEl.textContent = p.name;
          if (p.color) nameEl.style.color = "#" + p.color;
        }
        const scoreEl = panel.querySelector(".hxp_score_value");
        if (scoreEl) scoreEl.textContent = p.score;
        const reserveCur = panel.querySelector(".hxp_reserve_current");
        if (reserveCur) reserveCur.textContent = p.agents_in_pool;
        const blockadeCur = panel.querySelector(".hxp_blockade_current");
        if (blockadeCur) blockadeCur.textContent = p.blockades_in_pool;

        const grid = panel.querySelector(".hxp_reserve_grid");
        if (grid) {
          while (grid.firstChild) grid.removeChild(grid.firstChild);
          const agentsForP = (this.gamedatas.agents || []).filter(a => a.owner === id);
          const inPool = agentsForP.filter(a => a.state === 0);
          for (let t = 1; t <= 6; t++) {
            const matches = inPool.filter(a => a.type === t);
            for (let copy = 0; copy < 2; copy++) {
              const cell = _h("div", { className: "hxp_reserve_cell" });
              if (copy >= matches.length) cell.classList.add("is-spent");
              else cell.dataset.agentId = matches[copy].id;
              cell.dataset.agentType = t;
              cell.dataset.owner = id;
              cell.title = _(agentTypeDisplayName(t));    // I18N-35
              cell.style.backgroundColor = "var(--hxp-intel-" + INTEL_TYPE_BY_ID[t] + ", #444)";
              cell.addEventListener("click", () => this._onReserveCellClick(cell));
              grid.appendChild(cell);
            }
          }
        }

        if (id === Number(this.gamedatas.activeplayer_id)) {
          panel.classList.add("is-active");
        } else {
          panel.classList.remove("is-active");
        }
      });
    }

    _onReserveCellClick(cell) {
      if (!this.bga.players.isCurrentPlayerActive()) return;
      if (this._currentStateName() !== "spawn") return;
      if (cell.classList.contains("is-spent")) return;
      const ownerId = Number(cell.dataset.owner);
      if (ownerId !== Number(this.gamedatas.activeplayer_id)) return;

      document.querySelectorAll(".hxp_reserve_cell.is-armed").forEach(c => c.classList.remove("is-armed"));
      cell.classList.add("is-armed");
      this._uiState.armedAgentId = Number(cell.dataset.agentId);
      this._setStatus(_("Pick a ✦ hex to spawn."));    // I18N-36
    }

    _renderDiceTray(diceState) {
      const tray = document.getElementById("hxp_dice_tray");
      if (!tray) return;
      while (tray.firstChild) tray.removeChild(tray.firstChild);
      INTEL_DIE_KEYS.forEach(key => {
        const die = _h("div", { className: "hxp_die hxp_die_" + key });
        die.dataset.dieKey = key;
        // I18N-37: build dice tooltip via single-substitution wrapped key.
        die.title = dojo.string.substitute(
          _("${name} die: odd → SW, even → SE."),
          { name: _(intelTypeDisplayName(key)) }
        );
        const pips = _h("div", { className: "hxp_die_pips" });
        const arrow = _h("div", { className: "hxp_die_arrow" });
        die.appendChild(pips);
        die.appendChild(arrow);
        if (diceState && diceState[key]) {
          this._setDieFace(die, diceState[key]);
        }
        tray.appendChild(die);
      });
    }

    _setDieFace(dieNode, face) {
      const pips = dieNode.querySelector(".hxp_die_pips");
      const arrow = dieNode.querySelector(".hxp_die_arrow");
      if (!pips || !arrow) return;
      while (pips.firstChild) pips.removeChild(pips.firstChild);
      const count = (face === "odd") ? 1 : 2;
      for (let i = 0; i < count; i++) {
        pips.appendChild(_h("span", { className: "hxp_die_pip" }));
      }
      arrow.textContent = (face === "odd") ? "↙" : "↘";
    }

    _renderBag(size) {
      const el = document.querySelector(".hxp_bag_count");
      if (el) el.textContent = (size || 0);
    }

    _renderScores() {
      const players = this.gamedatas.players || {};
      Object.keys(players).forEach(pid => {
        const p = players[pid];
        this._slideScoreMarker(Number(pid), p.score);
      });
    }

    _slideScoreMarker(playerId, score) {
      // Score-track baked into board.png top-right (MISSING §10).
      // Anchor pixels to be confirmed [TODO G-02 / score-anchor calibration];
      // these placeholders approximate a two-row 0–10 / 11–20 strip.
      const isWhite = (playerId === this._whitePlayerId());
      const node = document.getElementById(isWhite ? "hxp_score_marker_p1" : "hxp_score_marker_p2");
      if (!node) return;
      const TRACK_LEFT_X = 740;
      const TRACK_TOP_Y_ROW1 = 30;
      const TRACK_TOP_Y_ROW2 = 70;
      const STEP_X = 38;
      let x, y;
      if (score <= 10) {
        x = TRACK_LEFT_X + score * STEP_X;
        y = TRACK_TOP_Y_ROW1;
      } else {
        x = TRACK_LEFT_X + (score - 11) * STEP_X;
        y = TRACK_TOP_Y_ROW2;
      }
      if (!isWhite) y += 14;
      node.style.left = x + "px";
      node.style.top  = y + "px";
      node.dataset.score = score;
    }

    /* ============================================================
     * Phase / status helpers
     * ============================================================ */

    _setPhaseBreadcrumb(stateName) {
      const map = {
        trickleDrawLeft: "trickle",
        trickleDrawRight: "trickle",
        trickleRoll: "trickle",
        trickleResolve: "trickle",
        spawn: "spawn",
        actions: "actions",
        analystBonusDecision: "actions",
        endOfTurnCleanup: null,
        gameSetup: null,
        gameEnd: null,
      };
      const target = map[stateName];
      document.querySelectorAll(".hxp_phase_step").forEach(li => {
        li.classList.toggle("is-current", li.dataset.phase === target);
      });
    }

    _setSubstate(text) {
      const el = document.getElementById("hxp_phase_substate");
      if (el) el.textContent = text || "";
    }

    _setStatus(text, kind) {
      const el = document.getElementById("hxp_status_bar");
      if (!el) return;
      el.textContent = text || "";
      el.classList.toggle("is-warning", kind === "warning");
      el.classList.toggle("is-info",    kind === "info");
    }

    _updateActionCounter(remaining, max) {
      const counter = document.getElementById("hxp_action_counter");
      if (!counter) return;
      counter.querySelector(".hxp_action_remaining").textContent = (remaining != null ? remaining : 0);
      counter.querySelector(".hxp_action_max").textContent = (max != null ? max : 3);
      counter.classList.toggle("is-zero", remaining === 0);
      counter.classList.toggle("is-boosted", max === 4);
    }

    _currentStateName() {
      // FE-10 (S1): prefer the cached live state from onEnteringState; fall
      // back to gamedatas.gamestate.name only on first paint before the
      // framework has called onEnteringState yet.
      if (this._currentState) return this._currentState;
      return (this.gamedatas && this.gamedatas.gamestate && this.gamedatas.gamestate.name) || null;
    }

    _getActivePlayerName() {
      const id = Number(this.gamedatas.activeplayer_id);
      const p  = (this.gamedatas.players || {})[id];
      return p ? p.name : _("Active player");    // I18N-38
    }

    /* ============================================================
     * Modals
     * ============================================================ */

    _showModal(id) {
      const m = document.getElementById(id);
      if (m) m.hidden = false;
    }

    _hideModal(id) {
      const m = document.getElementById(id);
      if (m) m.hidden = true;
    }

    _showHelpModal() {
      this._showModal("hxp_modal_help");
      this._renderHelpTab("quickref");
    }

    _renderHelpTab(tab) {
      const tabs = document.querySelectorAll(".hxp_help_tab");
      tabs.forEach(t => t.classList.toggle("is-active", t.dataset.tab === tab));
      const host = document.getElementById("hxp_help_content");
      if (!host) return;
      while (host.firstChild) host.removeChild(host.firstChild);

      const buildSection = (title, body) => {
        host.appendChild(_h("h4", { text: title }));
        body.forEach(line => {
          if (typeof line === "string") {
            host.appendChild(_h("p", { text: line }));
          } else {
            host.appendChild(line);
          }
        });
      };

      // FE-29 (S2): prefer the canonical content registry from
      // help_modal.js (window.HEXP_HELP_TABS) so we don't drift between
      // two sources. All strings still pass through _() at render time.
      const registry = (typeof window !== "undefined") ? window.HEXP_HELP_TABS : null;
      if (registry && registry[tab]) {
        const def = registry[tab];
        const renderedBody = (def.body || []).map(item => {
          if (typeof item === "string") return _(item);
          if (item && item.kind === "list") {
            const listEl = _h(item.ordered ? "ol" : "ul");
            (item.items || []).forEach(s => listEl.appendChild(_h("li", { text: _(s) })));
            return listEl;
          }
          return null;
        }).filter(Boolean);
        buildSection(_(def.title || ""), renderedBody);
        return;
      }

      // Fallback inline content (kept in sync with help_modal.js).
      // I18N-39..43: every literal title/body wrapped in _().
      switch (tab) {
        case "quickref": {
          const ul = _h("ul");
          const items = [
            _("Comms Specialist: Move loose intel up one space (1A) or down (1A + 1I)."),
            _("Analyst: When retiring with exactly 3 intel, draw 1 bonus tile and choose to keep or return."),
            _("Smuggler: Spend 1 intel to boost cap to 4 (1×/turn). Spend 1A + 1I to swap two un-pinned agents."),
            _("Engineer: Place blockade adjacent (1A) or anywhere (1I). Max 3 of your own active."),
            _("Hacker: Pin/unpin adjacent (1A; 1×/Hacker). Steal from any pinned enemy (1I; separate slot)."),
            _("Double Agent: Transfer one held intel to ANY agent in play (1A; no adjacency)."),
          ];
          items.forEach(t => ul.appendChild(_h("li", { text: t })));
          buildSection(_("Agent abilities"), [ul]);
          break;
        }
        case "honeypot":
          buildSection(_("Honeypot"), [
            _("Gray Honeypots are traps. Any agent that touches one is permanently removed; held intel + the Honeypot return to the bag. (Rulebook §9.4, [D-05b].)"),
          ]);
          break;
        case "blockade":
          buildSection(_("Blockades"), [
            _("A single blockade redirects intel to the open diagonal. Two blockades on both diagonals stop intel above from trickling (same applies to Comms vertical moves). Blockades freeze underlying intel; max 3 per player active; expire at the end of the opponent's next turn. (Rulebook §9.6, [D-04]/[D-07].)"),
          ]);
          break;
        case "phases": {
          const ol = _h("ol");
          ol.appendChild(_h("li", { text: _("Trickle — draw 2 intel into entry hexes; roll 6 dice; resolve trickle.") }));
          ol.appendChild(_h("li", { text: _("Spawn — up to 3 spawns into ✦ hexes.") }));
          ol.appendChild(_h("li", { text: _("Actions — up to 3 (4 with Smuggler boost) actions.") }));
          buildSection(_("Phases"), [ol]);
          break;
        }
        case "win":
          buildSection(_("Win conditions"), [
            _("First to 20 points wins (rulebook §8.1). A player with zero pool AND zero on board loses immediately ([D-17])."),
          ]);
          break;
      }
    }

    _showIntroModal() {
      this._showModal("hxp_modal_intro");
      const host = document.getElementById("hxp_intro_slides");
      if (!host) return;
      let slide = 0;
      // I18N-44: every intro slide title and body wrapped with _().
      const slidesData = [
        { title: _("Goal"),      body: _("Score 20 points by retiring agents holding intel. Each turn has 3 phases: Trickle / Spawn / Actions.") },
        { title: _("Agents"),    body: _("Six agent types: Comms, Analyst, Smuggler, Engineer, Hacker, Double Agent — each with a unique ability.") },
        { title: _("Watch out"), body: _("Honeypots instantly remove agents. Agents hold at most 3 intel; a 4th dumps all to bag. Retiring scores ALL held intel (State Secret = 4 pts).") },
      ];
      const render = () => {
        while (host.firstChild) host.removeChild(host.firstChild);
        host.appendChild(_h("h3", { text: slidesData[slide].title }));
        host.appendChild(_h("p",  { text: slidesData[slide].body  }));
      };
      render();
      const prev = document.getElementById("hxp_btn_intro_prev");
      const next = document.getElementById("hxp_btn_intro_next");
      const skip = document.getElementById("hxp_btn_intro_skip");
      const dismiss = () => {
        try { localStorage.setItem("hxp_intro_seen", "true"); } catch (e) { /* ignore */ }
        this._hideModal("hxp_modal_intro");
      };
      if (prev) prev.onclick = () => { slide = Math.max(0, slide - 1); render(); };
      if (next) next.onclick = () => {
        if (slide < slidesData.length - 1) { slide += 1; render(); }
        else { dismiss(); }
      };
      if (skip) skip.onclick = dismiss;
    }

    _wireStaticHandlers() {
      const closeBtn = document.getElementById("hxp_btn_help_close");
      if (closeBtn) closeBtn.onclick = () => this._hideModal("hxp_modal_help");
      document.querySelectorAll(".hxp_help_tab").forEach(t => {
        t.addEventListener("click", () => this._renderHelpTab(t.dataset.tab));
      });
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
          this._clearArmed();
          this._clearHighlights();
          ["hxp_modal_help", "hxp_modal_intel_choice", "hxp_modal_steal", "hxp_modal_intro"]
            .forEach(id => this._hideModal(id));
        }
      });
      document.addEventListener("click", (e) => {
        document.querySelectorAll(".hxp_btn_dropdown.is-open").forEach(d => {
          if (!d.contains(e.target)) d.classList.remove("is-open");
        });
      });
      const keepBtn   = document.getElementById("hxp_btn_analyst_keep");
      const returnBtn = document.getElementById("hxp_btn_analyst_return");
      const helpBtn   = document.getElementById("hxp_btn_analyst_help");
      // FE-09 (S1): keep/return must only fire when the modal is legitimately
      // visible AND we're the active player AND we're in the
      // analystBonusDecision state. Otherwise a stray click on a stale modal
      // would push an action through bgaPerformAction (BGA_PRIMER §11 warns
      // against programmatic-action paths in the wrong state).
      if (keepBtn)   keepBtn.addEventListener("click", () => {
        if (this._currentStateName() !== "analystBonusDecision") return;
        if (!this.bga.players.isCurrentPlayerActive()) return;
        this.bga.actions.performAction("actAnalystKeep");
        this._hideModal("hxp_modal_analyst");
      });
      if (returnBtn) returnBtn.addEventListener("click", () => {
        if (this._currentStateName() !== "analystBonusDecision") return;
        if (!this.bga.players.isCurrentPlayerActive()) return;
        this.bga.actions.performAction("actAnalystReturn");
        this._hideModal("hxp_modal_analyst");
      });
      if (helpBtn)   helpBtn.addEventListener("click", () => this._showHelpModal());
    }

    /* ============================================================
     * Notifications — CONTRACT.md §2 (every name has a handler)
     * ============================================================ */

    // FE-03 (S2): canonical BGA framework name (auto-invoked on gamegui).
    setupNotifications() {
      // The framework calls this itself, and setup() also calls it explicitly
      // (FE-03). Subscribing twice would run every handler — and every
      // animation — twice per notification, so make it idempotent.
      if (this._notificationsBound) return;
      this._notificationsBound = true;

      const list = [
        ["gameStarted",            1000],
        ["intelDrawn",              250],
        ["diceRolled",              600],
        ["trickleResolved",        1100],
        ["agentSpawned",            250],
        ["agentMoved",              250],
        ["intelTransferred",        200],
        ["agentRetired",            450],
        ["analystBonusDrawn",       400],
        ["analystBonusKept",        300],
        ["analystBonusReturned",    300],
        ["analystBonusSkipped",     600],
        ["blockadePlaced",          250],
        ["blockadeExpired",         250],
        ["agentPinned",             200],
        ["agentUnpinned",           200],
        ["pinExpired",              200],
        ["intelStolen",             250],
        ["agentSwapped",            300],
        ["agentRemovedHoneypot",    300],
        ["agentDumpedOvercapacity", 300],
        ["actionsBoosted",          200],
        ["intelMoved",              250],
        ["scoreUpdated",            300],
        ["turnEnded",               300],
        ["gameEnded",               800],
      ];

      list.forEach(([name, dur]) => {
        const handler = "notif_" + name;
        if (typeof this[handler] === "function") {
          dojo.subscribe(name, this, handler);
          this.bga.gameui.notifqueue.setSynchronous(name, dur);
        }
      });
    }

    /* ============================================================
     * Notification handlers — UI_SPEC §6.1
     * ============================================================ */

    notif_gameStarted(n) {
      const root = document.getElementById("hxp_root");
      if (root) root.classList.add("hxp_anim_fade_in");
      this._renderBag(n.args.bag_size);
    }

    notif_intelDrawn(n) {
      const args = n.args;
      if (args.skipped) {
        // I18N-45: substituted single-key sentence.
        this._setStatus(
          dojo.string.substitute(
            _("Bag empty — ${side}-side draw skipped."),
            { side: args.side }
          ),
          "info"
        );
        return;
      }
      this._renderBag(args.new_bag_size);
      this._placeIntelNode({
        id: args.tile_id, type: args.type, hex: args.hex, stack_order: 0,
      });
      const score = INTEL_SCORE_BY_ID[args.type] || 0;
      (this.gamedatas.intel_revealed = this.gamedatas.intel_revealed || []).push({
        id: args.tile_id, type: args.type, score_value: score,
      });
    }

    notif_diceRolled(n) {
      const dice = n.args.dice_state || {};
      INTEL_DIE_KEYS.forEach(key => {
        const node = document.querySelector('.hxp_die[data-die-key="' + key + '"]');
        if (!node) return;
        node.classList.remove("hxp_anim_die");
        void node.offsetWidth;
        node.classList.add("hxp_anim_die");
        if (dice[key]) this._setDieFace(node, dice[key]);
      });
    }

    notif_trickleResolved(n) {
      // §3.5 + §6.2 composite.
      const args = n.args;
      const moves = args.moves || [];
      moves.forEach((mv, idx) => {
        const intelNode = this._intelNodes[mv.tile_id];
        if (!intelNode) return;
        const stagger = idx * 30;
        intelNode.style.setProperty("--hxp-stagger", stagger + "ms");
        const px = this.hexToPixel(mv.to_hex.q, mv.to_hex.r);
        intelNode.style.transition =
          "left 250ms ease " + stagger + "ms, top 250ms ease " + stagger + "ms, opacity 200ms ease " + (stagger + 250) + "ms";
        intelNode.style.left = px.x + "px";
        intelNode.style.top  = px.y + "px";
        if (mv.off_board) {
          setTimeout(() => {
            intelNode.style.opacity = "0";
            setTimeout(() => intelNode.remove(), 250);
            delete this._intelNodes[mv.tile_id];
          }, 250 + stagger);
        }
      });

      (args.honeypot_removals || []).forEach((rem) => {
        const aNode = this._agentNodes[rem.agent_id];
        if (aNode) {
          aNode.style.transition = "opacity 300ms ease";
          aNode.style.opacity = "0";
          setTimeout(() => { aNode.remove(); delete this._agentNodes[rem.agent_id]; }, 300);
        }
        (rem.intel_returned || []).forEach(intelId => {
          const iNode = this._intelNodes[intelId];
          if (iNode) {
            iNode.style.transition = "opacity 300ms ease";
            iNode.style.opacity = "0";
            setTimeout(() => { iNode.remove(); delete this._intelNodes[intelId]; }, 300);
          }
        });
      });

      (args.over_capacity_dumps || []).forEach((dump) => {
        const agent = (this.gamedatas.agents || []).find(a => a.id === dump.agent_id);
        if (agent) {
          agent.intel_held = (agent.intel_held || []).filter(
            id => !(dump.dumped_intel || []).includes(id)
          );
          this._renderHeldIntelBadges(agent);
        }
        // FE-01 (S1): also remove the dumped intel's DOM nodes (loose-intel
        // node + any stale held-intel badges). Per CONTRACT §2.5
        // dumped_intel lists every tile id returned to the bag; without
        // explicit cleanup ghost badges/nodes accumulate.
        (dump.dumped_intel || []).forEach(intelId => {
          const iNode = this._intelNodes[intelId];
          if (iNode) {
            iNode.style.transition = "opacity 200ms ease";
            iNode.style.opacity = "0";
            setTimeout(() => {
              iNode.remove();
              delete this._intelNodes[intelId];
            }, 200);
          }
          document.querySelectorAll(
            '.hxp_intel_badge[data-intel-id="' + intelId + '"]'
          ).forEach(b => b.remove());
        });
      });

      setTimeout(() => this._renderBag(args.new_bag_size), 1000);
    }

    notif_agentSpawned(n) {
      const a = n.args;
      this._spawnAgentNode({
        id: a.agent_id, owner: a.owner, type: a.type, hex: a.hex,
        intel_held: [], pinned_until_turn: null,
        spawned_on_turn: a.spawned_on_turn, state: 1,
      });
      const player = this.gamedatas.players[a.owner];
      if (player) {
        player.agents_in_pool = a.agents_in_pool;
        const panel = this._panelFor(a.owner);
        if (panel) {
          const cur = panel.querySelector(".hxp_reserve_current");
          if (cur) {
            cur.textContent = a.agents_in_pool;
            cur.classList.add("hxp_anim_pop");
            setTimeout(() => cur.classList.remove("hxp_anim_pop"), 150);
          }
        }
      }
    }

    notif_agentMoved(n) {
      const a = n.args;
      const node = this._agentNodes[a.agent_id];
      if (!node) return;
      const px = this.hexToPixel(a.to_hex.q, a.to_hex.r);
      node.style.transition = "left 250ms ease, top 250ms ease";
      node.style.left = px.x + "px";
      node.style.top  = px.y + "px";

      (a.picked_up_intel || []).forEach(id => {
        const iNode = this._intelNodes[id];
        if (iNode) { iNode.remove(); delete this._intelNodes[id]; }
      });
      const agent = (this.gamedatas.agents || []).find(x => x.id === a.agent_id);
      if (agent) {
        agent.hex = a.to_hex;
        agent.intel_held = (agent.intel_held || []).concat(a.picked_up_intel || []);
        this._renderHeldIntelBadges(agent);
      }
      this._echoActionCounter(a.actions_remaining);
    }

    notif_intelTransferred(n) {
      const a = n.args;
      const fromAgent = (this.gamedatas.agents || []).find(x => x.id === a.from_agent_id);
      const toAgent   = (this.gamedatas.agents || []).find(x => x.id === a.to_agent_id);
      if (fromAgent) {
        fromAgent.intel_held = (fromAgent.intel_held || []).filter(id => id !== a.intel_id);
        this._renderHeldIntelBadges(fromAgent);
      }
      if (toAgent) {
        toAgent.intel_held = (toAgent.intel_held || []).concat([a.intel_id]);
        this._renderHeldIntelBadges(toAgent);
      }
      this._echoActionCounter(a.actions_remaining);
    }

    notif_agentRetired(n) {
      // FE-06 (S1): animate each scored-intel badge sliding to the score
      // panel (staggered 50ms) per UI_SPEC §6.1. Also cleans badges via the
      // per-agent map (FE-02) so no ghost badges remain after retire.
      const a = n.args;
      const ownerPanel = this._panelFor(a.agent_owner);
      const scoredIntel = a.scored_intel || [];
      const badges = (this._intelBadgeNodes[a.agent_id] || []).slice();

      scoredIntel.forEach((tile, idx) => {
        const badge = badges[idx];
        if (!badge || !ownerPanel) return;
        const panelRect = ownerPanel.getBoundingClientRect();
        const layer = badge.parentNode;
        const layerRect = layer ? layer.getBoundingClientRect() : { left: 0, top: 0 };
        const targetX = (panelRect.left - layerRect.left) + panelRect.width / 2;
        const targetY = (panelRect.top  - layerRect.top)  + 40;
        const stagger = idx * 50;
        badge.style.transition =
          "left 200ms ease " + stagger + "ms, top 200ms ease " + stagger +
          "ms, opacity 200ms ease " + (stagger + 150) + "ms";
        badge.style.left = targetX + "px";
        badge.style.top  = targetY + "px";
        setTimeout(() => {
          badge.style.opacity = "0";
          setTimeout(() => { if (badge.parentNode) badge.parentNode.removeChild(badge); }, 200);
        }, stagger + 200);
      });
      delete this._intelBadgeNodes[a.agent_id];

      const node = this._agentNodes[a.agent_id];
      if (node) {
        node.style.transition = "opacity 250ms ease, transform 250ms ease";
        node.style.opacity = "0";
        setTimeout(() => { node.remove(); delete this._agentNodes[a.agent_id]; }, 250);
      }
      const player = this.gamedatas.players[a.agent_owner];
      if (player) {
        player.score = a.new_score;
        const panel = this._panelFor(a.agent_owner);
        if (panel) {
          const sv = panel.querySelector(".hxp_score_value");
          if (sv) sv.textContent = a.new_score;
        }
        this._slideScoreMarker(Number(a.agent_owner), a.new_score);
      }

      // FE-07 (S2): when analyst_bonus_pending, surface an opponent banner
      // until analystBonusKept/Returned/Skipped fires. Active player gets
      // their own "Decide on Analyst bonus…" prompt elsewhere.
      if (a.analyst_bonus_pending) {
        if (Number(this.bga.players.getCurrentPlayerId()) !== Number(a.agent_owner)) {
          const ownerPlayer = (this.gamedatas.players || {})[a.agent_owner];
          const ownerName = ownerPlayer ? ownerPlayer.name : _("Active player");
          this._setStatus(
            dojo.string.substitute(
              _("${player_name} is deciding the Analyst bonus…"),
              { player_name: ownerName }
            ),
            "info"
          );
        }
      }
    }

    notif_analystBonusDrawn(n) {
      // PRIVATE to active player [D-20]. Show modal.
      // FE-08 (S1): defense-in-depth — bail if we're not the active player.
      // The notif is server-private per CONTRACT §4.1, but if a regression
      // ever misroutes it, we must NOT leak the bonus tile type to opponents.
      if (Number(this.bga.players.getCurrentPlayerId()) !== Number(this.gamedatas.activeplayer_id)) {
        return;
      }
      const a = n.args;
      const tileEl = document.getElementById("hxp_modal_analyst_tile");
      const nameEl = document.getElementById("hxp_modal_analyst_name");
      const valEl  = document.getElementById("hxp_modal_analyst_value");
      const keepBtn   = document.getElementById("hxp_btn_analyst_keep");
      const returnBtn = document.getElementById("hxp_btn_analyst_return");
      if (tileEl) {
        tileEl.className = "hxp_modal_analyst_tile hxp_intel hxp_intel_face hxp_intel_" + INTEL_TYPE_BY_ID[a.type];
      }
      // I18N-46..49: every visible string wrapped or substituted.
      if (nameEl) nameEl.textContent = a.type
        ? _(intelTypeDisplayName(a.type))
        : _("Bonus tile");
      if (valEl)  valEl.textContent  = dojo.string.substitute(
        _("+${value} points"),
        { value: a.score_value || 0 }
      );
      if (keepBtn)   keepBtn.textContent   = dojo.string.substitute(
        _("Keep (+${value} pts)"),
        { value: a.score_value || 0 }
      );
      if (returnBtn) returnBtn.textContent = _("Return to bag");
      this._renderBag(a.new_bag_size);
      this._showModal("hxp_modal_analyst");
    }

    notif_analystBonusKept(n) {
      const a = n.args;
      this._renderBag(a.new_bag_size);
      this._hideModal("hxp_modal_analyst");
      const player = this.gamedatas.players[a.player_id];
      if (player) {
        player.score = a.new_score;
        const panel = this._panelFor(a.player_id);
        if (panel) {
          const sv = panel.querySelector(".hxp_score_value");
          if (sv) sv.textContent = a.new_score;
        }
        this._slideScoreMarker(Number(a.player_id), a.new_score);
      }
      // FE-07 (S2): clear the spectator's "<player> is deciding…" banner.
      this._setStatus("");
    }

    notif_analystBonusReturned(n) {
      this._renderBag(n.args.new_bag_size);
      this._hideModal("hxp_modal_analyst");
      // FE-07 (S2): clear the spectator's "<player> is deciding…" banner.
      this._setStatus("");
    }

    notif_analystBonusSkipped(n) {
      this._setStatus(_("Bag empty — bonus forfeited."), "info");    // I18N-50
      this._hideModal("hxp_modal_analyst");
      setTimeout(() => this._setStatus(""), 600);
    }

    notif_blockadePlaced(n) {
      const a = n.args;
      this._placeBlockadeNode({
        id: a.blockade_id, owner: a.owner, hex: a.hex,
        placed_on_turn: a.placed_on_turn,
      });
      const player = this.gamedatas.players[a.owner];
      if (player) {
        player.blockades_in_pool = a.blockades_in_pool;
        const panel = this._panelFor(a.owner);
        if (panel) {
          const cur = panel.querySelector(".hxp_blockade_current");
          if (cur) cur.textContent = a.blockades_in_pool;
        }
      }
      this._echoActionCounter(a.actions_remaining);
    }

    notif_blockadeExpired(n) {
      (n.args.cleared_blockades || []).forEach(b => {
        const node = this._blockadeNodes[b.blockade_id];
        if (node) {
          node.style.transition = "opacity 250ms ease, transform 250ms ease";
          node.style.opacity = "0";
          setTimeout(() => { node.remove(); delete this._blockadeNodes[b.blockade_id]; }, 250);
        }
        const player = this.gamedatas.players[b.owner];
        if (player) {
          player.blockades_in_pool = (player.blockades_in_pool || 0) + 1;
          const panel = this._panelFor(b.owner);
          if (panel) {
            const cur = panel.querySelector(".hxp_blockade_current");
            if (cur) cur.textContent = player.blockades_in_pool;
          }
        }
      });
    }

    notif_agentPinned(n) {
      const a = n.args;
      const agent = (this.gamedatas.agents || []).find(x => x.id === a.target_agent_id);
      if (agent) {
        agent.pinned_until_turn = a.pinned_until_turn;
        this._renderPinMarker(agent);
      }
      this._echoActionCounter(a.actions_remaining);
    }

    notif_agentUnpinned(n) {
      const a = n.args;
      const agent = (this.gamedatas.agents || []).find(x => x.id === a.target_agent_id);
      if (agent) agent.pinned_until_turn = null;
      const node = this._pinNodes[a.target_agent_id];
      if (node) {
        node.style.transition = "opacity 200ms ease";
        node.style.opacity = "0";
        setTimeout(() => { node.remove(); delete this._pinNodes[a.target_agent_id]; }, 200);
      }
      const aNode = this._agentNodes[a.target_agent_id];
      if (aNode) aNode.classList.remove("is-pinned");
      this._echoActionCounter(a.actions_remaining);
    }

    notif_pinExpired(n) {
      (n.args.cleared_agents || []).forEach(c => {
        const node = this._pinNodes[c.agent_id];
        if (node) {
          node.style.transition = "opacity 200ms ease";
          node.style.opacity = "0";
          setTimeout(() => { node.remove(); delete this._pinNodes[c.agent_id]; }, 200);
        }
        const aNode = this._agentNodes[c.agent_id];
        if (aNode) aNode.classList.remove("is-pinned");
        const agent = (this.gamedatas.agents || []).find(x => x.id === c.agent_id);
        if (agent) agent.pinned_until_turn = null;
      });
    }

    notif_intelStolen(n) {
      const a = n.args;
      const victim = (this.gamedatas.agents || []).find(x => x.id === a.target_agent_id);
      const hacker = (this.gamedatas.agents || []).find(x => x.id === a.hacker_id);
      if (victim) {
        victim.intel_held = (victim.intel_held || []).filter(id => id !== a.stolen_intel.id);
        this._renderHeldIntelBadges(victim);
      }
      if (hacker) {
        hacker.intel_held = (hacker.intel_held || [])
          .filter(id => id !== a.intel_spent.id)
          .concat([a.stolen_intel.id]);
        this._renderHeldIntelBadges(hacker);
      }
      this._renderBag(a.new_bag_size);
      this._echoActionCounter(a.actions_remaining);
    }

    notif_agentSwapped(n) {
      const a = n.args;
      const aNode = this._agentNodes[a.agent_a_id];
      const bNode = this._agentNodes[a.agent_b_id];
      if (aNode) {
        const px = this.hexToPixel(a.agent_a_new_hex.q, a.agent_a_new_hex.r);
        aNode.style.transition = "left 300ms ease, top 300ms ease";
        aNode.style.left = px.x + "px";
        aNode.style.top  = px.y + "px";
      }
      if (bNode) {
        const px = this.hexToPixel(a.agent_b_new_hex.q, a.agent_b_new_hex.r);
        bNode.style.transition = "left 300ms ease, top 300ms ease";
        bNode.style.left = px.x + "px";
        bNode.style.top  = px.y + "px";
      }
      const aA = (this.gamedatas.agents || []).find(x => x.id === a.agent_a_id);
      const aB = (this.gamedatas.agents || []).find(x => x.id === a.agent_b_id);
      if (aA) aA.hex = a.agent_a_new_hex;
      if (aB) aB.hex = a.agent_b_new_hex;
      this._renderBag(a.new_bag_size);
      this._echoActionCounter(a.actions_remaining);
    }

    notif_agentRemovedHoneypot(n) {
      const a = n.args;
      const node = this._agentNodes[a.agent_id];
      if (node) {
        node.style.transition = "opacity 300ms ease";
        node.style.opacity = "0";
        setTimeout(() => { node.remove(); delete this._agentNodes[a.agent_id]; }, 300);
      }
      const pinNode = this._pinNodes[a.agent_id];
      if (pinNode) { pinNode.remove(); delete this._pinNodes[a.agent_id]; }
      document.querySelectorAll('.hxp_intel_badge[data-agent-id="' + a.agent_id + '"]').forEach(node2 => node2.remove());
      this._renderBag(a.new_bag_size);
    }

    notif_agentDumpedOvercapacity(n) {
      const a = n.args;
      const agent = (this.gamedatas.agents || []).find(x => x.id === a.agent_id);
      if (agent) {
        agent.intel_held = (agent.intel_held || []).filter(
          id => !(a.dumped_intel || []).includes(id)
        );
        this._renderHeldIntelBadges(agent);
      }
      this._renderBag(a.new_bag_size);
    }

    notif_actionsBoosted(n) {
      const a = n.args;
      this._updateActionCounter(a.new_actions_remaining, 4);
      const counter = document.getElementById("hxp_action_counter");
      if (counter) {
        counter.classList.add("hxp_anim_pulse");
        setTimeout(() => counter.classList.remove("hxp_anim_pulse"), 200);
      }
      this._renderBag(a.new_bag_size);
    }

    notif_intelMoved(n) {
      const a = n.args;
      const node = this._intelNodes[a.intel_id];
      if (node) {
        const px = this.hexToPixel(a.to_hex.q, a.to_hex.r);
        node.style.transition = "left 250ms ease, top 250ms ease";
        node.style.left = px.x + "px";
        node.style.top  = px.y + "px";
      }
      if (a.intel_spent) {
        const comms = (this.gamedatas.agents || []).find(x => x.id === a.comms_id);
        if (comms) {
          comms.intel_held = (comms.intel_held || []).filter(id => id !== a.intel_spent.id);
          this._renderHeldIntelBadges(comms);
        }
      }
      this._renderBag(a.new_bag_size);
      this._echoActionCounter(a.actions_remaining);
    }

    notif_scoreUpdated(n) {
      const a = n.args;
      const player = this.gamedatas.players[a.player_id];
      if (player) {
        player.score = a.new_score;
        const panel = this._panelFor(a.player_id);
        if (panel) {
          const sv = panel.querySelector(".hxp_score_value");
          if (sv) {
            sv.textContent = a.new_score;
            sv.classList.add("hxp_anim_pop");
            setTimeout(() => sv.classList.remove("hxp_anim_pop"), 150);
          }
        }
        this._slideScoreMarker(Number(a.player_id), a.new_score);
      }
      // modern BGA: use bga.playerPanels.getScoreCounter() accessor.
      try {
        if (this.bga && this.bga.playerPanels &&
            typeof this.bga.playerPanels.getScoreCounter === "function") {
          const ctr = this.bga.playerPanels.getScoreCounter(a.player_id);
          if (ctr && typeof ctr.toValue === "function") {
            ctr.toValue(a.new_score);
          }
        }
      } catch (e) {
        // Defensive: counter API surface may differ across framework versions.
      }
    }

    // NOTE: there is deliberately no notif_actionsRemaining handler.
    // CONTRACT §2.23: the server never emits a standalone `actionsRemaining`
    // notification; `actions_remaining` rides along in every mutating notif.

    notif_turnEnded(n) {
      const a = n.args;
      this.gamedatas.activeplayer_id = a.new_active_player_id;
      const turnEl = document.getElementById("hxp_turn_counter");
      if (turnEl) turnEl.textContent = a.new_turn_id;
      document.querySelectorAll(".hxp_player_panel").forEach(p => p.classList.remove("is-active"));
      const newPanel = this._panelFor(a.new_active_player_id);
      if (newPanel) newPanel.classList.add("is-active");
    }

    notif_gameEnded(n) {
      // I18N-51: build the game-over banner via single-key substitution so
      // the translator sees one complete sentence; reason text uses its own
      // wrapped key.
      const a = n.args;
      const winner = this.gamedatas.players[a.winner_id];
      const winName = winner ? winner.name : _("Winner");
      const reasonTxt = (a.win_reason === "score_20")
        ? _("reached 20 points")
        : _("opponent depleted");
      this._setStatus(
        dojo.string.substitute(
          _("Game over — ${player_name} wins (${win_reason_text})."),
          { player_name: winName, win_reason_text: reasonTxt }
        ),
        "info"
      );
    }

    /* ============================================================
     * Misc helpers
     * ============================================================ */

    _echoActionCounter(remaining) {
      if (remaining == null) return;
      const counter = document.getElementById("hxp_action_counter");
      if (!counter) return;
      const max = counter.classList.contains("is-boosted") ? 4 : 3;
      this._updateActionCounter(remaining, max);
    }

    _panelFor(playerId) {
      // FE-11 (S1): Anchor "self = left, opponent = right" via the current player id
      // (BGA convention). Falls back to player_no=1 → left when self is a
      // spectator. Sorting numeric ids was incorrect.
      const selfId = Number(this.bga.players.getCurrentPlayerId());
      const targetId = Number(playerId);
      let side;
      if (selfId && (selfId === targetId)) {
        side = "left";
      } else if (selfId) {
        side = "right";
      } else {
        const players = this.gamedatas.players || {};
        const p = players[targetId];
        side = (p && Number(p.player_no) === 1) ? "left" : "right";
      }
      return document.querySelector('[data-side="' + side + '"]');
    }
}
