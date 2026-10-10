-- 090: Correct the eleven Dipolog/Dapitan supplied-producing branches.
--
-- These branches have an assigned commissary and may also produce locally. Their
-- old self_managed mode contradicted the assignment and made the delivery source
-- resolver choose local_production. "hybrid" records both facts and deliberately
-- resolves deliveries to the assigned commissary. Codes constrain this repair to
-- the owner-confirmed set; assigned_commissary_id IS NOT NULL prevents inventing
-- a supply relationship if a tenant has removed one. Re-running is a no-op.

UPDATE dl_branches
   SET default_supply_mode = 'hybrid'
 WHERE assigned_commissary_id IS NOT NULL
   AND code IN (
       'DAP-POLO1',
       'DAP-PRINCE1',
       'DAP-BAGTING1',
       'DPL-MP1',
       'DPL-RIZAL1',
       'DPL-GENLUNA1',
       'DPL-OBAY1',
       'DPL-KATIPUNAN1',
       'DPL-FISHPORT1',
       'DPL-MINAOG1',
       'DPL-HERITG1'
   );
