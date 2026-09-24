/** Run the reference-style site switches in the actual compiled admin. */
export default async function nativeAdminCheckinSmoke(page, baseUrl) {
    const passed = [];
    const assert = (ok, label) => { if (!ok) throw new Error(label); passed.push(label); };
    const groups = {
        ticket: {}, deposit: {}, invite: {}, site: {app_name: 'Mock Site'}, subscribe: {},
        frontend: {}, server: {}, email: {}, telegram: {}, app: {}, safe: {}
    };
    const posts = [], requests = [], pageErrors = [];
    let failSave = false, holdSave = false, heldSave = null;
    page.on('pageerror', error => pageErrors.push(error.message));
    const respond = (route, body, status = 200) => route.fulfill({
        status, contentType: 'application/json', body: JSON.stringify(body)
    });
    await page.unrouteAll({behavior: 'ignoreErrors'});
    await page.addInitScript(() => {
        if (/^https?:$/.test(location.protocol)) localStorage.setItem('authorization', 'native-admin-mock-token');
    });
    await page.route('**/api/v1/**', async route => {
        const request = route.request(), url = request.url();
        requests.push(url);
        if (request.method() === 'POST' && url.includes('/config/save')) {
            const body = request.headers()['content-type'].includes('application/json')
                ? request.postDataJSON() : Object.fromEntries(new URLSearchParams(request.postData()));
            posts.push({body, authorization: request.headers().authorization});
            if (failSave) return respond(route, {message: 'Saving settings failed'}, 422);
            if (holdSave) {
                holdSave = false;
                heldSave = {route, body};
                return;
            }
            if ('server_pull_interval' in body) {
                groups.server = {...groups.server, ...body};
                return respond(route, {data: true});
            }
            groups.site = {...groups.site, ...body};
            // Same reference linkage enforced by ConfigController::save.
            if ('checkin_enable' in body && Number(body.checkin_enable) === 0) groups.site.lucky_checkin_enable = 0;
            return respond(route, {data: true});
        }
        if (url.includes('/config/fetch')) return respond(route, {data: groups});
        if (url.includes('/config/getEmailTemplate') || url.includes('/config/getThemeTemplate') || url.includes('/plan/fetch')) return respond(route, {data: []});
        if (url.includes('/user/info')) return respond(route, {data: {is_admin: 1, email: 'admin@example.test'}});
        return respond(route, {data: {}});
    });
    await page.setViewportSize({width: 1280, height: 900});
    await page.goto(baseUrl + '/test#/config/system');
    const site = page.getByRole('tab', {name: '站点', exact: true});
    await site.click();
    const row = title => page.locator('.row').filter({has: page.getByText(title, {exact: true})}).last();
    const standard = row('签到功能').getByRole('switch');
    const lucky = row('运气签到').getByRole('switch');
    await standard.waitFor();
    assert(await standard.getAttribute('aria-checked') === 'false', 'Missing configuration defaults to check-in disabled');
    assert(await lucky.count() === 0, 'Lucky switch is hidden while check-in is disabled');
    assert(await page.getByRole('tab', {name: '签到', exact: true}).count() === 0, 'No separate check-in tab');
    assert(await page.locator('#native-checkin-settings, #checkin-min-gb, #checkin-max-gb').count() === 0, 'No custom range or dedicated form remains');
    const resources = await page.evaluate(() => performance.getEntriesByType('resource').map(entry => entry.name));
    assert(['umi.js', 'vendors.async.js', 'components.async.js'].every(asset => resources.some(url => url.includes('/assets/admin/' + asset))), 'Runs all actual compiled bundles');
    const saveAction = async action => {
        const saved = page.waitForResponse(response => response.url().includes('/config/save'));
        await action();
        return saved;
    };
    const updated = async action => {
        const fetched = page.waitForResponse(response => response.url().includes('/config/fetch'));
        await saveAction(action);
        await fetched;
    };
    await updated(() => standard.click());
    await lucky.waitFor();
    assert(Number(posts.at(-1).body.checkin_enable) === 1, 'Reference site autosave enables check-in');
    assert(posts.at(-1).body.app_name === 'Mock Site', 'Check-in uses the existing site group');
    assert(posts.at(-1).authorization === 'native-admin-mock-token', 'Existing authenticated request helper is used');
    assert(await lucky.getAttribute('aria-checked') === 'false', 'Lucky remains disabled until explicitly enabled');
    await updated(() => lucky.click());
    assert(Number(posts.at(-1).body.lucky_checkin_enable) === 1, 'Lucky switch persists through site autosave');
    assert(Number(groups.site.checkin_enable) === 1 && Number(groups.site.lucky_checkin_enable) === 1, 'Both modes can be enabled');
    await updated(() => standard.click());
    assert(Number(groups.site.lucky_checkin_enable) === 0 && await lucky.count() === 0, 'Disabling main check-in also disables and hides lucky mode');
    await updated(() => standard.click());
    assert(await lucky.getAttribute('aria-checked') === 'false', 'Re-enabling main mode does not silently enable lucky');
    const name = page.getByPlaceholder('请输入站点名称', {exact: true});
    await updated(() => name.fill('Existing site settings'));
    assert(posts.at(-1).body.app_name === 'Existing site settings', 'Other site fields retain their existing save flow');
    assert(Number(groups.site.checkin_enable) === 1 && Number(groups.site.lucky_checkin_enable) === 0, 'Other site edits retain both check-in flags');
    failSave = true;
    await saveAction(() => lucky.click());
    await page.getByText('Saving settings failed', {exact: true}).waitFor();
    assert(Number(groups.site.lucky_checkin_enable) === 0, 'Failed save leaves server values intact');
    failSave = false;
    await page.reload();
    await standard.waitFor();
    assert(await standard.getAttribute('aria-checked') === 'true' && await lucky.getAttribute('aria-checked') === 'false', 'Reload restores persisted state after a failed save');
    await updated(() => lucky.click());
    assert(Number(groups.site.lucky_checkin_enable) === 1, 'Existing controls recover after a failed save');
    const beforeQueue = posts.length;
    holdSave = true;
    await lucky.click();
    await page.waitForRequest(request => request.url().includes('/config/save'));
    await page.getByRole('tab', {name: '节点', exact: true}).click();
    await row('节点拉取动作轮询间隔').locator('input').fill('65');
    await page.waitForTimeout(1750); // The existing config debounce is 1500 ms.
    assert(posts.length === beforeQueue + 1, 'Another group waits for an in-flight configuration write');
    const queued = page.waitForResponse(response => response.url().includes('/config/save') && response.request().postData().includes('server_pull_interval'));
    groups.site = {...groups.site, ...heldSave.body};
    await respond(heldSave.route, {data: true});
    await queued;
    assert(Number(groups.server.server_pull_interval) === 65, 'Queued group is saved after the prior request completes');
    await site.click();
    await updated(() => lucky.click());
    assert(!requests.some(url => url.includes('key=checkin')), 'No custom check-in group fetch remains');
    assert(!posts.some(({body}) => 'checkin_min_gb' in body || 'checkin_max_gb' in body), 'Removed range fields are never submitted');
    assert(pageErrors.length === 0, 'Actual compiled application has no page errors');
    await row('签到功能').scrollIntoViewIfNeeded();
    return passed;
}
