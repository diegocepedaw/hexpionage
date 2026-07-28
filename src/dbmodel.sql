-- Hexpionage — BGA database schema
-- per docs/specs/STATE_MODEL.md §2 + DECISIONS.md (D-04, D-06b, D-07, D-10b, D-15, D-19)

-- ------------------------------------------------------------------
-- Player table extensions (BGA provides `player` automatically)
-- ------------------------------------------------------------------

ALTER TABLE `player`
    ADD COLUMN `agents_remaining`     TINYINT UNSIGNED NOT NULL DEFAULT 12,
    ADD COLUMN `blockades_remaining`  TINYINT UNSIGNED NOT NULL DEFAULT 3;

-- ------------------------------------------------------------------
-- agent — 24 rows total (12 per player) per [D-10b]
--   state: 0=in_pool, 1=on_board, 2=removed
--   type_id: 1=comms_specialist, 2=analyst, 3=smuggler, 4=engineer,
--            5=hacker, 6=double_agent
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `agent` (
    `id`                          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner`                       INT(10)  UNSIGNED NOT NULL,
    `type_id`                     TINYINT  UNSIGNED NOT NULL,
    `state`                       TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    `hex_q`                       TINYINT  SIGNED   NULL DEFAULT NULL,
    `hex_r`                       TINYINT  SIGNED   NULL DEFAULT NULL,
    `pinned_until_turn`           INT      UNSIGNED NULL DEFAULT NULL,
    `spawned_on_turn`             INT      UNSIGNED NULL DEFAULT NULL,
    `hacker_pin_used_this_turn`   TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    `hacker_steal_used_this_turn` TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_agent_owner_state`   (`owner`, `state`),
    KEY `idx_agent_hex`           (`hex_q`, `hex_r`),
    KEY `idx_agent_type`          (`type_id`),
    KEY `idx_agent_pinned`        (`pinned_until_turn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- intel_tile — 47 rows total per [D-19]
--   state: 0=in_bag, 1=on_board, 2=on_agent, 3=scored, 4=returned_to_bag
--   type_id: 1=honeypot(0), 2=industrial_tech(2), 3=leaked_email(2),
--            4=blackmail(2), 5=security_credential(3), 6=state_secret(4)
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `intel_tile` (
    `id`           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_id`      TINYINT  UNSIGNED NOT NULL,
    `score_value`  TINYINT  UNSIGNED NOT NULL,
    `state`        TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    `hex_q`        TINYINT  SIGNED   NULL DEFAULT NULL,
    `hex_r`        TINYINT  SIGNED   NULL DEFAULT NULL,
    `agent_id`     SMALLINT UNSIGNED NULL DEFAULT NULL,
    `scored_by`    INT(10)  UNSIGNED NULL DEFAULT NULL,
    `stack_order`  TINYINT  UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_intel_state`         (`state`),
    KEY `idx_intel_hex`           (`hex_q`, `hex_r`),
    KEY `idx_intel_agent`         (`agent_id`),
    KEY `idx_intel_scored_by`     (`scored_by`),
    KEY `idx_intel_type`          (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- blockade — placed by Engineers; expires per [D-07]
--   state: 1=on_board, 2=expired
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `blockade` (
    `id`              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner`           INT(10)  UNSIGNED NOT NULL,
    `hex_q`           TINYINT  SIGNED   NOT NULL,
    `hex_r`           TINYINT  SIGNED   NOT NULL,
    `placed_on_turn`  INT      UNSIGNED NOT NULL,
    `state`           TINYINT  UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_blockade_owner_state` (`owner`, `state`),
    KEY `idx_blockade_hex`         (`hex_q`, `hex_r`),
    KEY `idx_blockade_placed`      (`placed_on_turn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
