# BGA Patterns

> Author: A1 — BGA Platform Research Agent. Three reusable patterns. Citations inline. Community-only claims tagged `[NOT CONFIRMED]`.

---

## Pattern 1 — Hex grid layout (pointy-top axial, absolute positioning)

### Recommendation

Use **pointy-top hexagons** with **axial coordinates `(q, r)`** rendered via **absolute positioning** inside a sized hex-board container. Each hex is its own DOM node and serves as its own click target — no pixel-to-hex conversion is needed at runtime.

### Why

- BGA has **no official hex-grid documentation page** (the Studio doc index does not list one) [https://en.doc.boardgamearena.com/Studio]. The Cookbook's only mention of hex tiles is a clip-path tip [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook]. Implementations must therefore be community-derived; tag every choice `[NOT CONFIRMED]`.
- Axial coordinates support vector arithmetic (move-by-direction) and arbitrary board shapes; offset-row systems break under add/subtract [https://www.redblobgames.com/grids/hexagons/].
- Pointy-top fits boards taller than wide and yields cleaner column alignment with two odd/even row classes [https://www.redblobgames.com/grids/hexagons/].
- Absolute positioning sidesteps CSS Grid's even/odd row logic and keeps each hex independently addressable — critical for click-target correctness and for animation primitives that move one node at a time [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].

### CSS skeleton `[NOT CONFIRMED]`

```css
.hex-board {
  position: relative;
  /* fixed pixel size so dimensions are integer at zoomFactor */
  width: 880px;
  height: 760px;
}
.hex {
  position: absolute;
  width: 80px;
  height: 92px; /* pointy-top: height = width * 2/sqrt(3) ≈ width * 1.1547 */
  background-image: url(img/hexes.png);
  background-size: 80px auto;
  cursor: pointer;
  z-index: 10;
}
.hex.selectable { outline: 2px solid #ffd54a; }
.hex.target     { outline: 2px solid #4caf50; }
```

Per BGA rules: single CSS file [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css], z-index < 900 [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css], integer pixel dimensions to avoid Safari rounding [https://en.doc.boardgamearena.com/Game_interface_stylesheet:_yourgamename.css].

### JS click-target wiring `[NOT CONFIRMED]`

For a pointy-top hex at axial `(q, r)` with horizontal spacing `W = 80` and vertical spacing `H = 69` (= 80 × 3/4 × 1.1547):

```js
function placeHex(q, r) {
  const x = W * (q + r / 2);
  const y = H * r;
  const node = document.createElement('div');
  node.id = `hex_${q}_${r}`;
  node.className = 'hex';
  node.style.left = `${x}px`;
  node.style.top  = `${y}px`;
  node.dataset.q = q;
  node.dataset.r = r;
  document.querySelector('.hex-board').appendChild(node);
  return node;
}
// Wire clicks to active player input only — never call performAction in loops.
node.addEventListener('click', (e) => {
  if (!this.bga.gameui.isCurrentPlayerActive()) return;
  this.onHexClicked(parseInt(node.dataset.q, 10), parseInt(node.dataset.r, 10));
});
```

The wired `click` handler invokes `this.bga.actions.performAction(...)` only in response to user input, satisfying the pre-release rule against programmatic action initiation [https://en.doc.boardgamearena.com/Pre-release_checklist].

### Notes

- Pieces ride on top of hexes via separate absolutely-positioned nodes parented to the same board, animated with `slideToObject` / `attachToNewParent` [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js]. Style animatable nodes with class selectors only — the framework may clone them [https://en.doc.boardgamearena.com/BgaAnimations].
- For irregular hex art use `clip-path: polygon(...)` per the Cookbook's only hex hint [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook].
- The community has shipped both CSS Grid offset-row and absolute-axial in production; the choice is project-level taste and is `[NOT CONFIRMED]` either way.

---

## Pattern 2 — Hidden bag draw

### Recommendation

Keep the bag as a **server-side multiset table** keyed by piece type with a count column. On draw: enumerate one slot per remaining unit, pick a uniform random index via `bga_rand`, decrement the count, persist within the action's transaction, then notify only the recipient privately. When the drawn piece is later revealed publicly (placed on the board), emit the public notification at that moment — not at draw time.

### Why

- `bga_rand($min, $max)` is the only RNG that meets BGA's reproducibility and entropy requirements; PHP's `array_rand`/`shuffle` are explicitly insufficient [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php]. Pre-release flags any other RNG as a blocker [https://en.doc.boardgamearena.com/BGA_Studio_Cookbook].
- Mutations inside an action are wrapped in an implicit transaction — exceptions roll back DB *and* notifications atomically, so a failed draw cannot leak partial state [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- `notify->player` carries the secret to one player; `notify->all` is forbidden for hidden info per submission rules [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php] [https://en.doc.boardgamearena.com/Pre-release_checklist].

### Server skeleton

```php
function drawFromBag(int $playerId, int $count): array {
    $drawn = [];
    for ($i = 0; $i < $count; $i++) {
        $remaining = (int)$this->getUniqueValueFromDB(
            "SELECT SUM(count) FROM bag");
        if ($remaining <= 0) break;
        $pick = bga_rand(1, $remaining);

        // Walk the multiset to find which type the index lands on.
        $rows = $this->getObjectListFromDB(
            "SELECT type, count FROM bag WHERE count > 0 ORDER BY type ASC");
        $cursor = 0;
        $chosen = null;
        foreach ($rows as $row) {
            $cursor += (int)$row['count'];
            if ($pick <= $cursor) { $chosen = $row['type']; break; }
        }
        $this->DbQuery("UPDATE bag SET count = count - 1 WHERE type = '$chosen'");
        $drawn[] = $chosen;
    }
    // Private: only the drawing player learns the identities.
    $this->bga->notify->player(
        $playerId, 'bagDrawnPrivate', '',
        ['pieces' => $drawn]
    );
    // Public: spectators and opponent see the count, not the identities.
    $this->bga->notify->all(
        'bagDrawnPublic',
        clienttranslate('${player_name} draws ${n} from the bag'),
        ['player_id' => $playerId, 'player_name' => self::getActivePlayerName(),
         'n' => count($drawn)]
    );
    return $drawn;
}
```

### Reveal at placement

The *public* identity goes out in the placement notification, not the draw notification — so a spectator cannot match draw order to subsequent reveals:

```php
$this->bga->notify->all(
    'piecePlaced',
    clienttranslate('${player_name} places a ${type_label} on ${hex_label}'),
    ['player_id' => $playerId, 'player_name' => self::getActivePlayerName(),
     'type' => $type, 'type_label' => $this->labelFor($type),
     'hex_label' => "($q,$r)", 'q' => $q, 'r' => $r]);
```

### Notes

- `getAllDatas()` must filter the `bag` table out of the response, or only return aggregate counts — never the per-type breakdown — based on whether the rules allow opponents to see the composition [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Audit by inspecting the WebSocket payload of every notification in dev tools; the pre-release checklist treats hidden-info leakage as a hard blocker [https://en.doc.boardgamearena.com/Pre-release_checklist].
- The Deck component's shuffle is an alternative for card games but is not a fit for tag-counted multisets [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

---

## Pattern 3 — Batched notification for atomic resolution

### Recommendation

When a single rule causes multiple state changes that resolve "simultaneously" (a board-wide trickle, an area-of-effect, a cascade), perform the entire resolution server-side inside one action and emit **one batched notification** that carries the full ordered move list. Do not emit one notification per piece. The client then sequences animations from the single payload.

### Why

- Per-piece notifications during atomic resolution let a careful spectator infer hidden state from arrival order or count, which violates the submission-quality rule on private information [https://en.doc.boardgamearena.com/Pre-release_checklist] [https://en.doc.boardgamearena.com/BGA_Studio_Guidelines].
- Notifications are queued and dispatched together when the action returns; grouping them logically into one event keeps the replay log compact and within the 128KB-per-action total [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- The client's `setupPromiseNotifications()` lets one handler return a Promise that resolves only when all sub-animations complete, so visual sequencing is preserved without multiple round-trips [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- The framework's transaction guarantees that either the whole resolution lands or none of it does [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].

### Server skeleton

```php
function resolveCascade(): void {
    $moves = []; // ordered list of {from, to, piece_id, kind}
    // ... compute the resolution against the DB ...
    foreach ($computedMoves as $m) {
        $this->DbQuery("UPDATE pieces SET location='{$m['to']}'
                        WHERE id={$m['piece_id']}");
        $moves[] = $m;
    }
    $this->bga->notify->all(
        'cascadeResolved',
        clienttranslate('Resolution complete'),
        ['moves' => $moves]
    );
}
```

### Client skeleton

```js
async notif_cascadeResolved(notif) {
  const board = this.gamedatas; // or local cached state
  for (const m of notif.args.moves) {
    const node = document.getElementById(`piece_${m.piece_id}`);
    const target = document.getElementById(`hex_${m.to}`);
    await new Promise((resolve) => {
      const anim = this.slideToObject(node, target, 250);
      anim.onEnd = () => { this.attachToNewParent(node, target); resolve(); };
      anim.play();
    });
  }
}
```

### Notes

- This pattern combined with `setSynchronous()` keeps the notification visually atomic for the viewer while remaining one server call [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- For very large move lists, prefer aggregating animations (`Promise.all`) over a serial `for-await` loop — but always check `bgaAnimationsActive()` first because BGA disables animations during fast replay and inactive tabs [https://en.doc.boardgamearena.com/Game_interface_logic:_yourgamename.js].
- If the resolution mixes public and private information (e.g., some moves reveal a hidden piece, others don't), split into two notifications: `cascadeResolvedPublic` via `notify->all` with redacted slots, and `cascadeResolvedPrivate` via `notify->player` with the full data — never inline the private parts into the public payload [https://en.doc.boardgamearena.com/Main_game_logic:_yourgamename.game.php].
- Style the moving DOM nodes via class selectors only, since the BGA animation component may clone or re-parent them [https://en.doc.boardgamearena.com/BgaAnimations].
