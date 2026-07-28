<!--
  hexpionage_hexpionage.tpl - HTML skeleton for the game area.

  BGA renders this through the phplib template engine; hexpionage.view.php
  fills every {TXT_*} variable in build_page(). This markup previously lived
  after the closing ?> of hexpionage.view.php, where the engine never saw it,
  so the raw {TXT_*} tokens were displayed to players verbatim.

  Source spec sections: UI_SPEC 1 (layout), 1.3, 1.4, 2 (hex grid),
  3 (per-state UI), 3.7b (analyst modal), 4, 9 (help modal), 10 (intro).
-->

<div id="hxp_root" class="hxp_root">

  <!-- ============================================================ -->
  <!-- TOP BAR — phase breadcrumb + turn counter (UI_SPEC §1.1, MISSING §5) -->
  <!-- ============================================================ -->
  <div id="hxp_topbar" class="hxp_topbar">
    <ol id="hxp_phase_breadcrumb" class="hxp_phase_breadcrumb">
      <li class="hxp_phase_step" data-phase="trickle">{TXT_PHASE_TRICKLE}</li>
      <li class="hxp_phase_step" data-phase="spawn">{TXT_PHASE_SPAWN}</li>
      <li class="hxp_phase_step" data-phase="actions">{TXT_PHASE_ACTIONS}</li>
    </ol>
    <div id="hxp_phase_substate" class="hxp_phase_substate"></div>
    <div id="hxp_turn_indicator" class="hxp_turn_indicator">
      <span class="hxp_turn_label">{TXT_TURN}</span>
      <span id="hxp_turn_counter" class="hxp_turn_counter">1</span>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- MAIN ROW — left panel | board | right panel (UI_SPEC §1.1)   -->
  <!-- ============================================================ -->
  <div id="hxp_main" class="hxp_main">

    <!-- LEFT PLAYER PANEL ------------------------------------------ -->
    <aside id="hxp_player_panel_left" class="hxp_player_panel" data-side="left">
      <header class="hxp_panel_header">
        <span class="hxp_panel_chevron">&#9654;</span>
        <span class="hxp_panel_player_name"></span>
      </header>
      <div class="hxp_panel_score">
        <span class="hxp_score_label">{TXT_SCORE}</span>
        <span class="hxp_score_value">0</span>
      </div>
      <div class="hxp_panel_reserve">
        <div class="hxp_reserve_count">
          <span class="hxp_reserve_label">{TXT_RESERVE}</span>
          <span class="hxp_reserve_current">12</span><span class="hxp_reserve_sep">/</span><span class="hxp_reserve_max">12</span>
        </div>
        <div class="hxp_reserve_grid"><!-- 6x2 mini-grid populated by JS --></div>
      </div>
      <div class="hxp_panel_blockades">
        <span class="hxp_blockade_label">{TXT_BLOCKADES}</span>
        <span class="hxp_blockade_current">3</span><span class="hxp_blockade_sep">/</span><span class="hxp_blockade_max">3</span>
        <div class="hxp_blockade_pip_row"></div>
      </div>
      <div class="hxp_panel_actions" hidden>
        <span class="hxp_actions_label">{TXT_ACTIONS}</span>
        <span class="hxp_actions_remaining">3</span><span class="hxp_actions_sep">/</span><span class="hxp_actions_max">3</span>
      </div>
      <div class="hxp_panel_onboard"><!-- per-agent summary populated by JS --></div>
    </aside>

    <!-- BOARD WRAP ------------------------------------------------- -->
    <div id="hxp_board_container" class="hxp_board_container">
      <div id="hxp_board_wrap" class="hxp_board_wrap">
        <div id="hxp_board_img" class="hxp_board_img" role="img" aria-label="{TXT_BOARD_ALT}"></div>

        <!-- Hex click overlay (UI_SPEC §2.2) ----------------------- -->
        <div id="hxp_hex_overlay" class="hxp_hex_overlay">
          <!-- one .hxp_hex per Field hex; populated by JS from gamedatas.board_layout -->
        </div>

        <!-- Visual layers (UI_SPEC §2.2) --------------------------- -->
        <div id="hxp_blockade_layer" class="hxp_blockade_layer"></div>
        <div id="hxp_intel_layer"    class="hxp_intel_layer"></div>
        <div id="hxp_agent_layer"    class="hxp_agent_layer"></div>
        <div id="hxp_pin_layer"      class="hxp_pin_layer"></div>

        <!-- Score markers (MISSING §1) ----------------------------- -->
        <div id="hxp_score_marker_p1" class="hxp_score_marker hxp_score_marker_white" data-score="0"></div>
        <div id="hxp_score_marker_p2" class="hxp_score_marker hxp_score_marker_black" data-score="0"></div>

        <!-- Honeypot first-contact tutorial overlay (UI_SPEC §5.3) -->
        <div id="hxp_tutorial_overlay" class="hxp_tutorial_overlay" hidden></div>
      </div>

      <!-- Dice tray + bag (UI_SPEC §1.1, §3.4) --------------------- -->
      <div id="hxp_dice_display" class="hxp_dice_display">
        <div id="hxp_dice_tray" class="hxp_dice_tray">
          <!-- 6 dice spans authored by JS:
               <div class="hxp_die hxp_die_<intel_id>" data-outcome="..."> -->
        </div>
        <div id="hxp_bag_widget" class="hxp_bag_widget">
          <span class="hxp_bag_icon" aria-hidden="true"></span>
          <span class="hxp_bag_count">47</span>
        </div>
      </div>
    </div>

    <!-- RIGHT PLAYER PANEL ----------------------------------------- -->
    <aside id="hxp_player_panel_right" class="hxp_player_panel" data-side="right">
      <header class="hxp_panel_header">
        <span class="hxp_panel_chevron">&#9654;</span>
        <span class="hxp_panel_player_name"></span>
      </header>
      <div class="hxp_panel_score">
        <span class="hxp_score_label">{TXT_SCORE}</span>
        <span class="hxp_score_value">0</span>
      </div>
      <div class="hxp_panel_reserve">
        <div class="hxp_reserve_count">
          <span class="hxp_reserve_label">{TXT_RESERVE}</span>
          <span class="hxp_reserve_current">12</span><span class="hxp_reserve_sep">/</span><span class="hxp_reserve_max">12</span>
        </div>
        <div class="hxp_reserve_grid"></div>
      </div>
      <div class="hxp_panel_blockades">
        <span class="hxp_blockade_label">{TXT_BLOCKADES}</span>
        <span class="hxp_blockade_current">3</span><span class="hxp_blockade_sep">/</span><span class="hxp_blockade_max">3</span>
        <div class="hxp_blockade_pip_row"></div>
      </div>
      <div class="hxp_panel_actions" hidden>
        <span class="hxp_actions_label">{TXT_ACTIONS}</span>
        <span class="hxp_actions_remaining">3</span><span class="hxp_actions_sep">/</span><span class="hxp_actions_max">3</span>
      </div>
      <div class="hxp_panel_onboard"></div>
    </aside>

  </div><!-- /#hxp_main -->

  <!-- ============================================================ -->
  <!-- ACTION BAR (UI_SPEC §1.1, §3.7.1)                              -->
  <!-- ============================================================ -->
  <div id="hxp_action_bar" class="hxp_action_bar">
    <div class="hxp_action_counter" id="hxp_action_counter">
      <span class="hxp_action_label">{TXT_ACTIONS}</span>
      <span class="hxp_action_remaining">3</span>
      <span class="hxp_action_sep">/</span>
      <span class="hxp_action_max">3</span>
    </div>
    <div id="hxp_action_buttons" class="hxp_action_buttons">
      <!-- Buttons authored by hexpionage.js::onUpdateActionButtons() -->
    </div>
    <div id="hxp_action_help_line" class="hxp_action_help_line"></div>
  </div>

  <!-- ============================================================ -->
  <!-- STATUS BAR (UI_SPEC §3 — banner messages)                      -->
  <!-- ============================================================ -->
  <div id="hxp_status_bar" class="hxp_status_bar" aria-live="polite"></div>

  <!-- ============================================================ -->
  <!-- LOG AREA placeholder — BGA renders its own log; this hosts    -->
  <!-- secondary game-specific log messages if needed.               -->
  <!-- ============================================================ -->
  <div id="hxp_log_area" class="hxp_log_area"></div>

  <!-- ============================================================ -->
  <!-- ANALYST BONUS MODAL (UI_SPEC §3.7b, [D-26])                    -->
  <!-- Hidden by default; shown only to active player on              -->
  <!-- analystBonusDecision state entry.                              -->
  <!-- ============================================================ -->
  <div id="hxp_modal_analyst" class="hxp_modal hxp_modal_analyst" hidden role="dialog" aria-modal="true">
    <div class="hxp_modal_backdrop"></div>
    <div class="hxp_modal_box">
      <h3 class="hxp_modal_title">{TXT_ANALYST_BONUS_TITLE}</h3>
      <div class="hxp_modal_body">
        <div id="hxp_modal_analyst_tile" class="hxp_modal_analyst_tile"></div>
        <div id="hxp_modal_analyst_name" class="hxp_modal_analyst_name"></div>
        <div id="hxp_modal_analyst_value" class="hxp_modal_analyst_value"></div>
      </div>
      <div class="hxp_modal_buttons">
        <button id="hxp_btn_analyst_keep"   class="hxp_btn hxp_btn_primary"></button>
        <button id="hxp_btn_analyst_return" class="hxp_btn hxp_btn_secondary"></button>
        <button id="hxp_btn_analyst_help"   class="hxp_btn hxp_btn_ghost" aria-label="{TXT_HELP_LABEL}">?</button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- INTEL CHOICE MODAL (UI_SPEC §4 — multi-intel disambiguation)   -->
  <!-- ============================================================ -->
  <div id="hxp_modal_intel_choice" class="hxp_modal hxp_modal_intel_choice" hidden role="dialog" aria-modal="true">
    <div class="hxp_modal_backdrop"></div>
    <div class="hxp_modal_box">
      <h3 class="hxp_modal_title" id="hxp_modal_intel_title"></h3>
      <div class="hxp_modal_body" id="hxp_modal_intel_choices"></div>
      <div class="hxp_modal_buttons">
        <button id="hxp_btn_intel_cancel" class="hxp_btn hxp_btn_secondary">{TXT_CANCEL}</button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- HACKER STEAL WIZARD MODAL (UI_SPEC §4.6)                       -->
  <!-- ============================================================ -->
  <div id="hxp_modal_steal" class="hxp_modal hxp_modal_steal" hidden role="dialog" aria-modal="true">
    <div class="hxp_modal_backdrop"></div>
    <div class="hxp_modal_box">
      <h3 class="hxp_modal_title" id="hxp_modal_steal_title"></h3>
      <div class="hxp_modal_body" id="hxp_modal_steal_body"></div>
      <div class="hxp_modal_buttons">
        <button id="hxp_btn_steal_back"   class="hxp_btn hxp_btn_secondary">{TXT_BACK}</button>
        <button id="hxp_btn_steal_cancel" class="hxp_btn hxp_btn_secondary">{TXT_CANCEL}</button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- HELP MODAL (UI_SPEC §9)                                        -->
  <!-- ============================================================ -->
  <div id="hxp_modal_help" class="hxp_modal hxp_modal_help" hidden role="dialog" aria-modal="true">
    <div class="hxp_modal_backdrop"></div>
    <div class="hxp_modal_box">
      <header class="hxp_modal_header">
        <h3 class="hxp_modal_title">{TXT_HELP_TITLE}</h3>
        <button id="hxp_btn_help_close" class="hxp_btn hxp_btn_ghost" aria-label="{TXT_CLOSE_LABEL}">&times;</button>
      </header>
      <nav class="hxp_help_tabs" id="hxp_help_tabs">
        <button class="hxp_help_tab is-active" data-tab="quickref">{TXT_HELP_TAB_QUICKREF}</button>
        <button class="hxp_help_tab" data-tab="honeypot">{TXT_HELP_TAB_HONEYPOT}</button>
        <button class="hxp_help_tab" data-tab="blockade">{TXT_HELP_TAB_BLOCKADE}</button>
        <button class="hxp_help_tab" data-tab="phases">{TXT_HELP_TAB_PHASES}</button>
        <button class="hxp_help_tab" data-tab="win">{TXT_HELP_TAB_WIN}</button>
      </nav>
      <div class="hxp_help_content" id="hxp_help_content"><!-- populated by help_modal.js --></div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- INTRO MODAL (UI_SPEC §10) — first-time only, suppressed via    -->
  <!-- localStorage flag `hxp_intro_seen=true`.                      -->
  <!-- ============================================================ -->
  <div id="hxp_modal_intro" class="hxp_modal hxp_modal_intro" hidden role="dialog" aria-modal="true">
    <div class="hxp_modal_backdrop"></div>
    <div class="hxp_modal_box">
      <div id="hxp_intro_slides" class="hxp_intro_slides"></div>
      <div class="hxp_modal_buttons">
        <button id="hxp_btn_intro_skip" class="hxp_btn hxp_btn_ghost">{TXT_INTRO_SKIP}</button>
        <button id="hxp_btn_intro_prev" class="hxp_btn hxp_btn_secondary">{TXT_INTRO_PREV}</button>
        <button id="hxp_btn_intro_next" class="hxp_btn hxp_btn_primary">{TXT_INTRO_NEXT}</button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- SUB-PHONE WARNING BANNER (UI_SPEC §1.3)                        -->
  <!-- ============================================================ -->
  <div id="hxp_subphone_banner" class="hxp_subphone_banner" hidden>
    <p>{TXT_SUBPHONE_WARNING}</p>
  </div>

</div><!-- /#hxp_root -->
