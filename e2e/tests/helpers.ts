import { expect, type Locator, type Page } from '@playwright/test';

/** "1 234 лота" / "12 345" → 1234 / 12345 (ru-RU groups with a narrow no-break space). */
export const num = (text: string | null): number => Number(String(text ?? '').replace(/[^\d]/g, ''));

/**
 * Signed in as the local tester before the page loads: the token comes from the local sign-in
 * (POST /api/auth/dev-login), put where the mini app keeps a browser's Telegram link.
 */
export async function signInMiniapp(page: Page): Promise<void> {
  const res = await page.request.post('/api/auth/dev-login');
  expect(res.ok(), 'the local sign-in answers: is laravel/.env APP_ENV=local?').toBeTruthy();
  const { data } = await res.json();
  await page.addInitScript(d => {
    localStorage.setItem('carbot_browser_token', d.token);
    localStorage.setItem('carbot_browser_chat_id', String(d.chat_id));
    localStorage.setItem('carbot_browser_user', JSON.stringify({ first_name: d.first_name, username: d.username }));
  }, data);
}

/** The mini app open with its results shown. */
export async function openMiniapp(page: Page): Promise<void> {
  await page.goto('/miniapp/');
  await expect(page.locator('#results-count b')).toBeVisible();
}

/** The Tom Select that replaced the <select> `selector`. */
const tomSelect = (page: Page, selector: string): Locator => page.locator(`${selector} + .ts-wrapper`);

export interface Choice { text: string; n: number }

/** The choices a Tom Select offers, with the lot count each shows (0 when it shows none). */
export async function choices(page: Page, selector: string): Promise<Choice[]> {
  const ts = tomSelect(page, selector);
  await ts.locator('.ts-control').click();
  const options = ts.locator('.ts-dropdown .option');
  await expect(options.first()).toBeVisible();
  const out: Choice[] = [];
  for (const o of await options.all()) {
    // Tom Select puts its own "option" class on the rendered .cat-opt: <span>name</span><span class="cat-opt__n">n</span>
    const parts = o.locator(':scope > span');
    if (await parts.count()) {
      out.push({ text: (await parts.first().innerText()).trim(), n: (await parts.count()) > 1 ? num(await parts.nth(1).innerText()) : 0 });
    } else {
      out.push({ text: (await o.innerText()).trim(), n: 0 });
    }
  }
  await close(page, selector);
  return out;
}

/** Picks the choice whose own text is exactly `text`. */
export async function choose(page: Page, selector: string, text: string): Promise<void> {
  const ts = tomSelect(page, selector);
  await ts.locator('.ts-control').click();
  await ts.locator('.ts-control input').fill(text);
  const option = ts.locator('.ts-dropdown .option').filter({
    has: page.locator(':scope > span:first-child', { hasText: new RegExp(`^${escape(text)}$`) }),
  }).or(ts.locator('.ts-dropdown .option', { hasText: new RegExp(`^${escape(text)}$`) }));
  await option.first().click();
  await close(page, selector);
}

/** A Tom Select stays open after a pick and would cover the selects below it. */
async function close(page: Page, selector: string): Promise<void> {
  await page.locator(selector).evaluate((el: HTMLSelectElement & { tomselect?: { close(): void; blur(): void } }) => {
    el.tomselect?.close();
    el.tomselect?.blur();
  });
}

const escape = (s: string) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Does `change` to the filters and gives how many cars the results then show. On a wide screen
 * the search runs by itself (450 ms after a change, the live count after 700 ms); on a phone
 * «Показать» runs it.
 */
export async function shown(page: Page, change: () => Promise<void>): Promise<number> {
  // a change made before (a pick not followed by shown) still has its search on a timer:
  // let it run out, so the answer awaited below is this change's
  await page.waitForTimeout(800);
  await page.waitForLoadState('networkidle');
  const searched = page.waitForResponse(r => r.url().includes('/api/search') && r.request().method() === 'POST');
  await change();
  const apply = page.locator('#filters-apply-btn');
  if (await apply.isVisible()) await apply.click();
  const total: number = (await (await searched).json())?.data?.total ?? 0;
  await expect(page.locator('#results-count b'), 'the results show what the search found').toHaveText(count(total));
  // the live count (700 ms) settles too, so the next change starts from a quiet page
  await page.waitForLoadState('networkidle');
  return total;
}

/** 6757 as the mini app writes it: "6 757" (ru-RU groups with a no-break space). */
const count = (n: number) => new RegExp(`^${n.toLocaleString('ru-RU').replace(/\s/g, '\\s')}$`);
