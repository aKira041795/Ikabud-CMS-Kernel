/**
 * ShellHarness — Stable testing API for workbench:app_shell.
 *
 * Provides navigation, sidebar, and user-context assertions without
 * depending on CSS class names.
 *
 * Usage:
 *   const shell = ShellHarness.from(page);
 *   await shell.expectUserDisplayed();
 *   await shell.expectActiveNav('Dashboard');
 *   await shell.navigateViaSidebar('All Job Orders');
 *
 * @see storage/application-profiles/ark-workbench/components/shell/app_shell.disyl
 */

// @ts-check

class ShellHarness {
    /**
     * @param {import('@playwright/test').Page} page
     */
    constructor(page) {
        this.page = page;
    }

    /** Get the app-shell main element */
    get locator() {
        return this.page.locator('[data-wb-component="app-shell"]');
    }

    /** Get the sidebar */
    get sidebar() {
        return this.page.locator('#wb-sidebar');
    }

    /** Get all nav items */
    get navItems() {
        return this.sidebar.locator('.wb-nav-item');
    }

    /** Get the active nav item */
    get activeNavItem() {
        return this.sidebar.locator('.wb-nav-item.is-active');
    }

    /** Assert the app shell is visible */
    async expectVisible() {
        await expect(this.locator).toBeVisible();
    }

    /** Assert a user display name is shown */
    async expectUserDisplayed() {
        const userEl = this.sidebar.locator('.p-4.border-b p');
        await expect(userEl).toBeVisible();
        await expect(userEl).not.toBeEmpty();
    }

    /** Assert an application name is shown */
    async expectAppName(expected) {
        const nameEl = this.sidebar.locator('h1');
        if (expected) {
            await expect(nameEl).toContainText(expected);
        } else {
            await expect(nameEl).toBeVisible();
        }
    }

    /** Assert the expected nav item is active */
    async expectActiveNav(label) {
        // Use filter to find the active item containing the label text
        // (nav items include emoji icons as text content)
        await expect(this.sidebar.locator('.wb-nav-item.is-active').filter({ hasText: label })).toHaveCount(1);
    }

    /** Navigate by clicking a sidebar nav item */
    async navigateViaSidebar(label) {
        await this.navItems.filter({ hasText: label }).first().click();
        await this.locator.waitFor({ state: 'visible', timeout: 10000 });
    }

    /** Get the sidebar section count */
    async sectionCount() {
        return await this.sidebar.locator('.wb-sidebar-section').count();
    }

    /** Toggle a sidebar section */
    async toggleSection(label) {
        const trigger = this.sidebar
            .locator('.wb-sidebar-section__trigger')
            .filter({ hasText: label })
            .first();
        await trigger.click();
    }

    /** Assert a section is collapsed */
    async expectSectionCollapsed(label) {
        const section = this.sidebar
            .locator('.wb-sidebar-section')
            .filter({ hasText: label })
            .first();
        await expect(section).toHaveClass(/wb-sidebar-section--collapsed/);
    }

    /** Assert the current page title */
    async expectPageTitle(expected) {
        await expect(this.page.locator('#wb-main h1')).toContainText(expected);
    }

    /** Assert the page family attribute */
    async expectPageFamily(expected) {
        await expect(this.locator).toHaveAttribute('data-wb-page-family', expected);
    }

    /** Assert the toast container exists */
    async expectToastContainer() {
        await expect(this.page.locator('#wb-toast-container')).toBeVisible();
    }
}

module.exports = { ShellHarness };
