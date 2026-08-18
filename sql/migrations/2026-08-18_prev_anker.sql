-- Nachtrag zum Briefing vom 18.08.2026: Vorstufen-Anker PREV_NET / PREV_GROSS.
-- {{P}} wird durch y5x_prod_ bzw. y5x_stg_ ersetzt.
ALTER TABLE {{P}}price_state
  ADD COLUMN last_reduction_at       DATE          NULL AFTER promo_started,
  ADD COLUMN pre_promo_net           DECIMAL(12,4) NULL AFTER pre_promo_gross,
  ADD COLUMN last_written_prev_net   DECIMAL(12,4) NULL AFTER last_written_30_gross,
  ADD COLUMN last_written_prev_gross DECIMAL(12,2) NULL AFTER last_written_prev_net;

ALTER TABLE {{P}}pss_write_log
  MODIFY COLUMN price_type ENUM('30_NET','30_GROSS','PREV_NET','PREV_GROSS') NOT NULL;
