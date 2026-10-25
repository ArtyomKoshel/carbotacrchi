import { expect, test } from '@playwright/test';
import { choices, choose, openMiniapp, shown, signInMiniapp } from './helpers';

/*
 * The mini app / site: the catalog cascade under the make must never lose a car — every
 * choice shows how many cars it holds, and the search finds exactly that many.
 */

test('signs in locally without Telegram', async ({ page }) => {
  await page.goto('/miniapp/');
  await page.getByTestId('dev-login').first().click();
  await expect(page.locator('#browser-link-bar')).toContainText('Local tester');
  await expect(page.locator('#results-count b')).toBeVisible();
});

test.describe('signed in', () => {
  test.beforeEach(async ({ page }) => {
    await signInMiniapp(page);
    await openMiniapp(page);
  });

  for (const make of ['Kia', 'Hyundai', 'BMW', 'Ford']) {
    test(`${make}: the models add up to the make, and each finds its own count`, async ({ page }) => {
      const makeTotal = await shown(page, () => choose(page, '#filter-make', make));
      expect(makeTotal).toBeGreaterThan(0);

      const models = (await choices(page, '#cat-model')).filter(c => c.n > 0);
      expect(models.reduce((sum, m) => sum + m.n, 0), 'the cascade holds every car of the make').toBe(makeTotal);

      const bySize = [...models].sort((a, b) => b.n - a.n);
      for (const m of [bySize[0], bySize[bySize.length - 1]]) {
        expect(await shown(page, () => choose(page, '#cat-model', m.text)), `${make} ${m.text}`).toBe(m.n);
      }
    });
  }

  test('Kia Morning: generation, then modification, then complectation narrow exactly', async ({ page }) => {
    await choose(page, '#filter-make', 'Kia');
    const modelTotal = await shown(page, () => choose(page, '#cat-model', 'Morning'));

    const gens = await choices(page, '#cat-gen');
    expect(gens.reduce((sum, g) => sum + g.n, 0), 'the generations hold every Morning').toBe(modelTotal);
    const gen = [...gens].sort((a, b) => b.n - a.n)[0];
    expect(await shown(page, () => choose(page, '#cat-gen', gen.text)), gen.text).toBe(gen.n);

    const mods = await choices(page, '#cat-mod');
    expect(mods.reduce((sum, m) => sum + m.n, 0), 'the modifications hold the generation').toBe(gen.n);
    const mod = [...mods].sort((a, b) => b.n - a.n)[0];
    expect(await shown(page, () => choose(page, '#cat-mod', mod.text)), mod.text).toBe(mod.n);

    if (await page.locator('[data-level="comp"]').isVisible()) {
      const comps = await choices(page, '#cat-comp');
      expect(comps.reduce((sum, c) => sum + c.n, 0), 'the complectations hold the modification').toBe(mod.n);
      const comp = [...comps].sort((a, b) => b.n - a.n)[0];
      expect(await shown(page, () => choose(page, '#cat-comp', comp.text)), comp.text).toBe(comp.n);
    }
  });

  test('Ford: a car Encar names no generation for stays findable as «Поколение не указано»', async ({ page }) => {
    await choose(page, '#filter-make', 'Ford');
    const models = (await choices(page, '#cat-model')).filter(c => c.n > 0).sort((a, b) => b.n - a.n);
    for (const m of models) {
      await choose(page, '#cat-model', m.text);
      const none = (await choices(page, '#cat-gen')).find(g => g.text === 'Поколение не указано');
      if (none) {
        expect(await shown(page, () => choose(page, '#cat-gen', none.text)), `${m.text}, generation not stated`).toBe(none.n);
        return;
      }
    }
    throw new Error('no Ford model offers «Поколение не указано»');
  });

  test('a lot card names the car by the catalog', async ({ page }) => {
    await choose(page, '#filter-make', 'Kia');
    await shown(page, () => choose(page, '#cat-model', 'Morning'));
    await page.locator('#results [data-i]').first().click();
    const title = page.locator('#lot-view .lot__title');
    await expect(title).toBeVisible();
    await expect(title).toContainText('Kia');
    await expect(title).toContainText('Morning');
  });
});
