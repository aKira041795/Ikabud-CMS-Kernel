-- 089: Assign the Pagadian branches to their commissary.
--
-- OWNER, 2026-10-10: "set all pagadian branches to be assigned with the Pagadian
-- commissary".
--
-- WHY THIS IS NEEDED
-- The Pagadian area was created with a commissary (PAG-COMMISARY1, is_commissary = 1) and six
-- retail branches (GATAS, DUMALINAO, LUMBIA, SAN JOSE, LABANGAN, PULMONES), but all six were
-- left with assigned_commissary_id = NULL and default_supply_mode = 'self_managed'. So the
-- commissary supplied nobody: the snapshot reported exactly one commissary with no supplying
-- branches, which is the opposite of the intended topology.
--
-- WHY THE MODE IS SET TOO, NOT JUST THE ID
-- `default_supply_mode` is not decorative. handlers-deliveries.php resolves the supply source
-- from it:
--     'commissary_supplied' => 'commissary'
--     'self_managed'        => 'local_production'
--     'hybrid'              => 'commissary'
-- Setting only assigned_commissary_id would leave the branch resolving as local_production,
-- i.e. deliveries would still believe the stock came from the branch's own production. The six
-- branches have zero rows in dl_production_runs and dl_production_movements, so
-- 'commissary_supplied' is the mode the evidence supports. (A branch that genuinely produces
-- AND receives supply is 'hybrid' — which also resolves to 'commissary' — so this migration is
-- safe to revisit if any of these branches later start producing.)
--
-- SCOPE
-- The six retail branches only. The commissary itself is deliberately NOT touched: it is the
-- production source, and a commissary that produces is legitimately self-managed.
-- The eleven Dipolog/Dapitan branches that carry an assigned commissary while declaring
-- 'self_managed' are ALSO deliberately untouched — the owner has said some branches have
-- production as well as supply, and the recorded production evidence does not yet identify
-- which. Changing them without that answer would change where deliveries think stock comes
-- from, which is not a decision to take silently.
--
-- IDEMPOTENT
-- Setting the same two values repeatedly is a semantic no-op, so a rerun changes nothing.
-- There is no same-table subquery in the WHERE clause: the commissary id is materialised
-- through a derived table, which is the form MySQL 5.7 accepts (see the 1093 restriction).

UPDATE dl_branches
   SET assigned_commissary_id = (
           SELECT cm.id
             FROM (SELECT id FROM dl_branches WHERE code = 'PAG-COMMISARY1' LIMIT 1) AS cm
       ),
       default_supply_mode = 'commissary_supplied'
 WHERE is_commissary = 0
   AND code IN (
        'PAG-GATAS1',
        'PAG-DUMALINAO1',
        'PAG-LUMBIA1',
        'PAG-SAN JOSE1',
        'PAG-LABANGAN1',
        'PAG-PULMONES1'
   )
   AND EXISTS (SELECT 1 FROM (SELECT id FROM dl_branches WHERE code = 'PAG-COMMISARY1' LIMIT 1) AS guard);
