/**
 * Real browser smoke on already-created E2E state (tools/sprint2_real_e2e.py).
 * Never intercepts Laravel endpoints. Does NOT write to GitHub.
 * Run only on isolated local/staging DB with MAINTAINGOV_SPRINT2_LIVE=1.
 */
import { test, expect, type Page } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

test.skip(process.env.MAINTAINGOV_SPRINT2_LIVE !== '1',
  'Opt in with MAINTAINGOV_SPRINT2_LIVE=1 on an isolated real stack');

const password = process.env.MAINTAINGOV_E2E_PASSWORD;
const stateFile = process.env.MAINTAINGOV_E2E_STATE_FILE ||
  path.resolve(process.cwd(), '../../tmp/sprint2-e2e-state.json');

function state() {
  if (!fs.existsSync(stateFile)) throw new Error('Run tools/sprint2_real_e2e.py prepare first: '+stateFile);
  const d = JSON.parse(fs.readFileSync(stateFile,'utf-8'));
  if (!d.backlog_id || !d.case_id) throw new Error('E2E state incomplete');
  return d as { case_id: number; backlog_id:number; edited_summary:string;
    source_branch?:string; phase:string; github_pr_number?:number };
}

async function login(page:Page, role:'maintenance'|'developer') {
  if (!password) throw new Error('Set MAINTAINGOV_E2E_PASSWORD in PowerShell, never commit the password');
  const email = role === 'maintenance'
    ? (process.env.MAINTAINGOV_E2E_MAINTENANCE_EMAIL || 'maintenance@maintaingov.test')
    : (process.env.MAINTAINGOV_E2E_DEVELOPER_EMAIL || 'developer@maintaingov.test');
  await page.goto('/login');
  await page.getByLabel('Email dinas', {exact:true}).fill(email);
  await page.getByLabel('Kata sandi', {exact:true}).fill(password);
  await page.getByRole('button', {name:'Masuk',exact:true}).click();
  await expect(page).not.toHaveURL(/\/login$/,{timeout:30000});
}

test('REAL UC02/UC03: Maintenance case and saved backlog are visible after reload',async ({page})=>{
  const s = state();
  await login(page,'maintenance');
  await page.goto(`/maintenance-case/${s.case_id}`);
  await expect(page.getByText('Valid',{exact:true}).first()).toBeVisible();
  await page.goto(`/maintenance-backlog/${s.backlog_id}`);
  await expect(page.getByText(s.edited_summary,{exact:false}).first()).toBeVisible();
  await page.reload();
  await expect(page.getByText(s.edited_summary,{exact:false}).first()).toBeVisible();
});

test('REAL UC04/UC05: Developer sees actual assigned issue and confirmed PR if present',async ({page})=>{
  const s=state();
  await login(page,'developer');
  await page.goto(`/maintenance-issue/${s.backlog_id}`);
  await expect(page.getByText(s.edited_summary,{exact:false}).first()).toBeVisible();
  if(s.source_branch) await expect(page.getByText(s.source_branch,{exact:false}).first()).toBeVisible();
  if(s.phase==='pr_created'){
    await page.goto(`/maintenance-issue/${s.backlog_id}/pull-request`);
    await expect(page.getByText(`#${s.github_pr_number}`,{exact:false}).first()).toBeVisible();
    await expect(page.getByText('Open',{exact:true}).first()).toBeVisible();
  }
});
