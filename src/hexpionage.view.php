<?php
/**
 * hexpionage.view.php
 *
 * Controller half of BGA's view/template pair. The HTML skeleton lives in
 * hexpionage_hexpionage.tpl; this file only assigns the {TXT_*} variables that
 * the phplib template engine substitutes into it. Anything emitted after this
 * file's PHP close tag is NOT processed by the engine and reaches the player as
 * literal text, which is exactly what happened on the first Studio table.
 * Source spec sections:
 *   - UI_SPEC §1 (layout overview)
 *   - UI_SPEC §1.4 (z-index scheme)
 *   - UI_SPEC §2 (hex grid technique)
 *   - UI_SPEC §3 (per-state UI)
 *   - UI_SPEC §3.7b (analyst bonus modal)
 *   - UI_SPEC §9 (help modal)
 *   - design/MISSING.md §3-§5 (CSS-only widgets)
 *   - BGA_PRIMER §6 (CSS prefix `hxp_`, single CSS file)
 *
 * NOTE: this is the modern .view.php form. All custom IDs/classes use the
 * `hxp_` prefix per BGA_PRIMER §6 and DECISIONS. Z-index values are managed
 * by hexpionage.css and stay below 900 (BGA dialogs occupy 950+).
 */

require_once(APP_BASE_PATH . "view/common/game.view.php");

class view_hexpionage_hexpionage extends game_view {
    public function getGameName() {
        return "hexpionage";
    }

    public function build_page($viewArgs) {
        // I18N-65..85: populate every {TXT_*} placeholder so the rendered
        // HTML never shows raw token literals. All strings flow through
        // clienttranslate(...) so BGA's translator can extract them per
        // BGA_PRIMER §11 (PHP view template i18n convention).
        //
        // modern BGA: clienttranslate() vs legacy self::_() — HAL flagged the
        // legacy form. clienttranslate() is the correct extractor token in
        // modern .view.php templates.
        //
        // The action bar buttons are NOT defined here — they are produced
        // dynamically in hexpionage.js::onUpdateActionButtons() per
        // UI_SPEC §3.7.1, gated on legal_actions in state args.

        // Top bar — phase breadcrumb + turn counter (UI_SPEC §1.1)
        $this->tpl['TXT_PHASE_TRICKLE'] = clienttranslate("Trickle");
        $this->tpl['TXT_PHASE_SPAWN']   = clienttranslate("Spawn");
        $this->tpl['TXT_PHASE_ACTIONS'] = clienttranslate("Actions");
        $this->tpl['TXT_TURN']          = clienttranslate("Turn");

        // Player panels (UI_SPEC §1.1)
        $this->tpl['TXT_SCORE']     = clienttranslate("Score");
        $this->tpl['TXT_RESERVE']   = clienttranslate("Reserve");
        $this->tpl['TXT_BLOCKADES'] = clienttranslate("Blockades");
        $this->tpl['TXT_ACTIONS']   = clienttranslate("Actions");

        // Analyst Bonus modal (UI_SPEC §3.7b)
        $this->tpl['TXT_ANALYST_BONUS_TITLE'] = clienttranslate("Analyst Bonus");

        // Generic modal buttons (Intel choice + Hacker steal wizard, UI_SPEC §4)
        $this->tpl['TXT_CANCEL'] = clienttranslate("Cancel");
        $this->tpl['TXT_BACK']   = clienttranslate("Back");

        // Help modal (UI_SPEC §9)
        $this->tpl['TXT_HELP_TITLE']         = clienttranslate("Help");
        $this->tpl['TXT_HELP_TAB_QUICKREF']  = clienttranslate("Agent abilities");
        $this->tpl['TXT_HELP_TAB_HONEYPOT']  = clienttranslate("Honeypot");
        $this->tpl['TXT_HELP_TAB_BLOCKADE']  = clienttranslate("Blockades");
        $this->tpl['TXT_HELP_TAB_PHASES']    = clienttranslate("Phases");
        $this->tpl['TXT_HELP_TAB_WIN']       = clienttranslate("Win conditions");

        // Intro modal (UI_SPEC §10)
        $this->tpl['TXT_INTRO_SKIP'] = clienttranslate("Skip");
        $this->tpl['TXT_INTRO_PREV'] = clienttranslate("Previous");
        $this->tpl['TXT_INTRO_NEXT'] = clienttranslate("Next");

        // Sub-phone warning banner (UI_SPEC §1.3)
        $this->tpl['TXT_SUBPHONE_WARNING'] = clienttranslate("This game is best played on a tablet or larger screen.");

        // Inline-attribute placeholders (alt / aria-label) — replaces deprecated $this->_().
        $this->tpl['TXT_BOARD_ALT']   = clienttranslate("Hexpionage board");
        $this->tpl['TXT_HELP_LABEL']  = clienttranslate("Help");
        $this->tpl['TXT_CLOSE_LABEL'] = clienttranslate("Close");
    }
}
