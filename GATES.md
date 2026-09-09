# Gates: Dokploy staging environment plan for can-eye-budget-v2

Scope: Investigate the Dokploy server and the reference compare-build deployment, then produce a concrete, actionable plan (docs file) to stand up a "staging" environment for can-eye-budget-v2 on Dokploy, mirroring compare-build's setup with one Dokploy service per container.

- [x] G1: Dokploy server inventory captured (existing projects/environments/apps, and specifically how compare-build's project/environment/services are structured: app type, build method, env vars pattern, domains, databases).
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); console.log((s.match(/^## Dokploy inventory$/m)||[]).length)"
  EXPECT: /[1-9]/
  EVIDENCE: 1

- [x] G2: compare-build repo's container topology documented (every service/container it runs: web/app, queue, scheduler, db, cache, etc., with image/build source).
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); console.log((s.match(/^## compare-build container topology$/m)||[]).length)"
  EXPECT: /[1-9]/
  EVIDENCE: 1

- [x] G3: can-eye-budget-v2 requirements mapped to containers needed for staging (derived from composer.json/package.json/.env.example/config, not guessed).
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); console.log((s.match(/^## can-eye-budget-v2 container requirements$/m)||[]).length)"
  EXPECT: /[1-9]/
  EVIDENCE: 1

- [x] G4: can-eye.mrwilde.dev domain status on the Dokploy server confirmed (validated/pointed correctly or noted as pending DNS propagation).
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); console.log((s.match(/^## Domain status$/m)||[]).length)"
  EXPECT: /[1-9]/
  EVIDENCE: 1

- [x] G5: Final plan written with one Dokploy service defined per container, mirroring compare-build's staging setup (naming, env vars, build config, domain attach, deploy order) and saved under docs/.
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); console.log(fs.existsSync('docs/dokploy-staging-plan.md') ? (s.match(/^### Service:/gm)||[]).length : 0)"
  EXPECT: /[1-9]/
  EVIDENCE: 5

- [x] G6: Plan reviewed against compare-build for parity (manual) — every container compare-build runs has a corresponding planned service here, with deltas called out explicitly (not silently dropped).
  EVIDENCE: Measured 4 reference service rows and 5 planned service sections; all four reference rows are mapped, with Redis, no-standalone-frontend, and MariaDB-for-report-SQL deltas explicit.
- [x] G7: Consistency sweep completed — every reference container and every checked-in queue/schedule/storage dependency has one documented planned treatment; no duplicate configuration change was left unmatched.
  EVIDENCE: Repository-wide deployment sweep found all 9 required dependency tokens in source and plan: QUEUE_CONNECTION, CACHE_STORE, REDIS_HOST, SESSION_DRIVER, ShouldQueue, horizon:snapshot, schedule:work, FILESYSTEM_DISK, storage:link.

- [x] G8: Scope review completed — this planning task changed only the plan and gate ledger; application source and tests remain unchanged.
  EVIDENCE: Final git-status scope check reported application_source_or_test_rows=0; task-created files are GATES.md and docs/dokploy-staging-plan.md, with existing untracked skill files unchanged.

- [x] G9: Verification review completed — because no application behavior changed, no issue-specific test module applies; the plan and gate checks were exercised instead.
  EVIDENCE: No application source or test file changed, so an issue-specific full test module does not exist for this documentation-only change; gate-check exercised all eight runnable plan checks successfully.


- [x] G10: Every planned service has an explicit Dokploy build strategy tied to the fetched comparebuild Application records; no service assumes a missing repository Dockerfile and no service silently falls back to Nixpacks.
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); const n=(s.match(/do not select Nixpacks/g)||[]).length; const ids=['q6L3rVbJlA35H-GXkuQjA','Hvb0NgWmza7tE2PiU37Fb','de50uCfU75TcCqfihx2Mk']; const r=s.split('\\n').filter(line=>ids.some(id=>line.includes(id))&&line.includes('dockerfile')&&line.includes('Dockerfile')).length; console.log(JSON.stringify({nixpacks_explicit:n,reference_dockerfile_records:r}))"
  EXPECT: /\"nixpacks_explicit\":3.*\"reference_dockerfile_records\":3/
  EVIDENCE: {"nixpacks_explicit":3,"reference_dockerfile_records":3}

- [x] G11: The planned database is compatible with shipped report aggregation and the PostgreSQL reference divergence is explicit.
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); const markers=['can-eye-mariadb','DB_CONNECTION','mariadb','DATE_FORMAT','PostgreSQL would break']; const driver=s.split('\\n').some(line=>line.includes('DB_CONNECTION')&&line.includes('mariadb')); console.log(JSON.stringify({mariadb_connection:driver,mariadb_compatibility_markers:markers.filter(marker=>s.includes(marker)).length}))"
  EXPECT: /\"mariadb_connection\":true.*\"mariadb_compatibility_markers\":5/
  EVIDENCE: {"mariadb_connection":true,"mariadb_compatibility_markers":5}

- [x] G12: The deployment sequence is executable in order — the web Application deploys before migrations run, and Horizon and the scheduler deploy only after migrations.
  CHECK: node -e "const fs=require('fs'); const s=fs.readFileSync('docs/dokploy-staging-plan.md','utf8'); const web=s.indexOf('Deploy the web Application first'); const mig=s.indexOf('artisan migrate --force'); const workers=s.indexOf('Deploy the queue worker and scheduler services'); console.log(JSON.stringify({found:web>=0&&mig>=0&&workers>=0,ordered:mig>web&&workers>mig}))"
  EXPECT: /\"found\":true.*\"ordered\":true/
  EVIDENCE: {"found":true,"ordered":true}

<!--
ABANDON lines go here if a gate becomes impossible.
-->
