<?php
/**
 * Help tab (HelpController): a plain-language guide for non-technical users. Static text, no logic.
 * Keep it true to the code: every feature mentioned here must exist in app/Tools/*.php (tool names are given in brackets as an extra).
 * Section ids are used by the menu at the top.
 */
?>
<h1>Help</h1>

<nav class="card toc" aria-label="Help contents">
    <strong>On this page:</strong>
    <a href="#what">What this is</a>
    <a href="#connect">Connect to Google Ads</a>
    <a href="#talk">How to talk to it</a>
    <a href="#look">Look at your account and campaigns</a>
    <a href="#change">Change things safely</a>
    <a href="#write">Write ads and content</a>
    <a href="#media">Photos and videos</a>
    <a href="#create">Create a campaign</a>
    <a href="#conversions">Conversions and tracking</a>
    <a href="#safety">Safety and limits</a>
    <a href="#trouble">Troubleshooting</a>
</nav>

<section class="card" id="what">
    <h2>What this is</h2>
    <p>This plugin lets an AI assistant such as Claude work with your Google Ads account. You chat with Claude in plain words, for example
        "How are my campaigns doing?", and Claude uses this plugin to look at your account and to prepare changes.</p>
    <p>This website is the control panel. You use it to connect your Google account, to connect Claude, to approve changes, and to upload photos.
        You do not need to know any code.</p>
    <ul>
        <li><strong>Looking</strong> at your account (reports, campaigns, search terms) is always harmless: nothing is changed.</li>
        <li><strong>Changing</strong> something always goes through a <em>plan</em> that you read and approve first.</li>
    </ul>
</section>

<section class="card" id="connect">
    <h2>Connect to Google Ads</h2>
    <p>Do this once. The order matters: first Google, then Claude.</p>

    <h3>Step 1. Connect your Google account</h3>
    <ol>
        <li>Open the <a href="<?= e(url('/connections')) ?>">Connections</a> tab.</li>
        <li>In the "Google account" box, click <strong>Connect with Google</strong>.</li>
        <li>Sign in with the Google account that manages your Google Ads (the page tells you which address is accepted).</li>
        <li>On the Google consent screen, tick <strong>all</strong> the permissions (Google Ads and Analytics). If you skip one, some features will not work.</li>
        <li>You are sent back here and the box shows "Connected as ...".</li>
    </ol>
    <p class="help">If Google shows the error "redirect_uri_mismatch", the one-time setup described in the Google account box was not finished yet or has not
        taken effect. Wait a few minutes and try again.</p>

    <h3>Step 2. Add Claude as a client</h3>
    <ol>
        <li>In <a href="<?= e(url('/connections')) ?>">Connections</a>, find the "MCP clients" form (an "MCP client" is simply an app that is allowed to talk to this plugin).</li>
        <li>Give it a name you will recognise, for example "My Claude".</li>
        <li>Choose the <strong>Type</strong>: <code>claude-ai</code> if you use Claude on the claude.ai website or in its app;
            <code>claude-code</code> if you use Claude Code (a program run in a terminal).</li>
        <li>Choose the <strong>Access</strong>: "read and write" (Claude can prepare changes, which you still approve) or "read-only" (Claude can only look, never prepare changes).</li>
        <li>Click add. You get a <strong>Client ID</strong> and a <strong>Client secret</strong>. Copy them now: the secret is shown only once.</li>
    </ol>

    <h3>Step 3. Add the connector in Claude</h3>
    <p><strong>On claude.ai:</strong> Settings, then Connectors, then "Add custom connector". Paste the server address shown in the "How to connect" box
        in <a href="<?= e(url('/connections')) ?>">Connections</a> (it ends with <code>/mcp</code>). Open "Advanced settings", enter the Client ID and Client secret,
        click Add, then Connect. In a conversation, switch the connector on in the tools menu.</p>
    <p><strong>In Claude Code:</strong> after adding a client of type <code>claude-code</code>, the Connections page shows a ready-made command. Run it in a terminal,
        restart Claude Code, type <code>/mcp</code>, choose the connector and pick Authenticate.</p>
    <p>When it works, Claude appears under "Active connections" in the Connections tab.</p>
</section>

<section class="card" id="talk">
    <h2>How to talk to it</h2>
    <p>Just write what you want, as you would to a colleague. You do not need special words. The more specific you are (which campaign, which period, which goal),
        the better the answer. If Claude is unsure, it will ask you.</p>
    <p>Examples you can copy:</p>
    <ul>
        <li>"Give me an overview of my Google Ads account."</li>
        <li>"Which of my campaigns spent the most last month, and did they bring any conversions?"</li>
        <li>"Run a health check on my campaign called Spring Sale and tell me what is wrong."</li>
        <li>"Show me which search terms wasted money last month and suggest negative keywords."</li>
        <li>"What changed in my account in the last two weeks, and who changed it?"</li>
        <li>"Which devices and hours of the day bring the best results?"</li>
        <li>"Pause the campaign Old Promo."</li>
        <li>"Lower the daily budget of Spring Sale to 20 and show me the plan first."</li>
        <li>"Write five new headlines and three descriptions for my search ads in the campaign Spring Sale."</li>
        <li>"Add the cities Krakow and Gdansk to my campaign and exclude Warsaw."</li>
        <li>"Give me keyword ideas for a bakery in Poznan, with monthly searches."</li>
        <li>"Undo the last change you made."</li>
    </ul>
    <p class="help">Tip: whenever Claude proposes a change, ask "What exactly will change?" before you approve.</p>
</section>

<section class="card" id="look">
    <h2>Look at your account and campaigns</h2>
    <p>These requests only read data. They never change anything.</p>
    <ul>
        <li><strong>Account overview</strong>: account name, currency, time zone, how many campaigns are active or paused, and how much of the daily API allowance was used.</li>
        <li><strong>Campaign list</strong>: every campaign with its daily budget, bidding strategy and results (impressions, clicks, cost, conversions, click rate, average cost per click) for a period you choose.</li>
        <li><strong>Campaign details</strong>: ad groups, ads, keywords, negative keywords, locations and settings of one campaign. For Performance Max (PMax) campaigns also the asset groups, their assets and signals.</li>
        <li><strong>Health check</strong>: Claude audits a campaign and lists problems, such as an end date that has passed, conversion goals that are set up wrongly, or rejected ads.</li>
        <li><strong>Search terms</strong>: what people actually typed before seeing your ad, with cost and conversions. Good for finding wasted money.</li>
        <li><strong>Reports</strong>: conversions by action, share of searches you win or lose (impression share), Google's own recommendations (list only, nothing is applied),
            landing pages, single-day click details, results by device, weekday, hour, age and gender, ads that Google disapproved or limited, audiences, budget what-if simulations,
            invoices and billing, and account users and their roles.</li>
        <li><strong>Performance Max report</strong>: assets, networks, placements, locations, hours and search categories, optionally compared with an earlier period.</li>
        <li><strong>Change history</strong>: what this plugin changed, and what anyone changed in the account in the last weeks (who, when, what).</li>
        <li><strong>Keyword ideas</strong>: ideas from Google's Keyword Planner with average searches, competition and bid estimates.</li>
        <li><strong>Google Analytics 4 (GA4)</strong>: visits and events on your website, the data streams, the key events, and whether GA4 is linked to the right Google Ads account.</li>
    </ul>
    <p class="help">Tool names, if you are curious: account_overview, list_campaigns, campaign_details, campaign_health_check, search_terms, ads_report, pmax_report,
        change_history, keyword_ideas, ga4_report, ga4_key_events, ga4_data_streams, ga4_ads_links.</p>
</section>

<section class="card" id="change">
    <h2>Change things safely</h2>
    <h3>How a change works: plans</h3>
    <ol>
        <li>You ask Claude for a change, for example "Raise the budget of Spring Sale to 25 per day".</li>
        <li>Claude does <strong>not</strong> change anything yet. It prepares a <em>plan</em> and Google checks the plan without applying it.</li>
        <li>Claude shows you the plan, with a link to a plan page. Read it: it lists what will change, from what to what.</li>
        <li>You open the plan page, log in here if asked (a one-time link is e-mailed to you), and press the approve button.</li>
        <li>Only now is the change sent to Google Ads. If something fails, the plan page says so, and nothing half-done is hidden from you.</li>
    </ol>
    <p>Plan links work for 24 hours. A plan that you do not approve simply does nothing.</p>

    <h3>Undo</h3>
    <p>Ask "Undo the last change" (or name a specific plan). Claude prepares an undo plan that restores the old values, and you approve it like any other plan.
        Undo works for plans executed in the last 7 days.</p>

    <h3>What you can change</h3>
    <ul>
        <li><strong>Budget and name</strong> of a campaign.</li>
        <li><strong>Bidding</strong>: manual cost per click (with a maximum click price), maximise clicks, or maximise conversions.</li>
        <li><strong>Pause</strong> a campaign (including Performance Max). The plugin never switches a campaign on.</li>
        <li><strong>Dates</strong>: start date and end date (or remove the end date).</li>
        <li><strong>Where your ads show</strong>: add or remove locations, exclude places, or use a radius around a point; choose whether to reach people in or searching for a place.</li>
        <li><strong>Networks</strong>: for example Google Search only, or with partners.</li>
        <li><strong>Keywords and negative keywords</strong>: add or remove keywords; add or remove negative keywords (words you do not want to pay for) for one campaign or in a shared list.
            Match types: exact, phrase, broad. Claude can check for collisions with your existing keywords.</li>
        <li><strong>Account-wide exclusions</strong>: block websites, YouTube channels or videos, mobile apps and app categories from showing your ads in all campaigns, including a ready-made preset for kids' games and apps.</li>
        <li><strong>Ad groups</strong>: create them, with their keywords and ads.</li>
        <li><strong>Audiences</strong>: custom segments built from keywords or web pages, and audience signals for Performance Max.</li>
    </ul>
    <p class="help">Tool names: prepare_campaign_changes, prepare_campaign_pause, prepare_campaign_settings, prepare_negative_keywords, prepare_account_exclusions,
        prepare_custom_audience, prepare_undo.</p>
    <p class="help">Not available: hours-of-day ad schedules. Only start and end dates can be set.</p>
</section>

<section class="card" id="write">
    <h2>Write ads and content</h2>
    <p>Claude can draft the texts, and then put them into a plan for your approval.</p>
    <ul>
        <li><strong>Search ads (responsive search ads)</strong>: several headlines and descriptions that Google mixes. Ask for options and tell Claude your tone and offer.
            Ad texts cannot be edited in Google once saved, so a changed text becomes a new ad (the plan shows this).</li>
        <li><strong>Ad groups</strong>: themed groups of keywords with their own ads.</li>
        <li><strong>Extensions (assets)</strong>: sitelinks (extra links under your ad), callouts (short selling points), a call button with your phone number, a logo and a business name, and removing a lead form.</li>
        <li><strong>Performance Max asset groups</strong>: headlines, long headlines, descriptions, business name, images, logo, YouTube videos, final page address and the group name. You can also add a new group (it starts paused).</li>
        <li><strong>Search themes and audience signals</strong>: hints that tell Performance Max who your best customers are (search topics, custom segments, your own lists, age, gender, parental status).</li>
    </ul>
    <p class="help">Tool names: prepare_campaign_changes, prepare_campaign_assets, prepare_asset_group_changes, prepare_asset_group_signals, prepare_custom_audience.
        Web addresses in ads must belong to the allowed domains set by the server owner.</p>
</section>

<section class="card" id="media">
    <h2>Photos and videos</h2>
    <h3>Upload photos</h3>
    <ol>
        <li>Ask Claude: "Give me a link to upload images." Claude replies with an upload link (it works for 2 hours and needs no login).</li>
        <li>Open the link, or use the <a href="<?= e(url('/upload')) ?>">Upload</a> tab here. Drag JPG or PNG files onto the page; you can add many at once.</li>
        <li>Go back to Claude and say "I uploaded the images". Claude sees them (file name, dimensions, type).</li>
        <li>Tell Claude where to use them, for example "Add these images to the asset group Spring Sale". Claude prepares a plan, and you approve it.</li>
    </ol>
    <h3>Use videos from YouTube</h3>
    <ol>
        <li>Ask Claude for the video chooser link (it is the upload link with <code>/youtube</code> at the end), or open the Upload tab.</li>
        <li>Pick the videos of your YouTube channel that you want to use.</li>
        <li>Tell Claude "I chose the videos" and where to add them. Performance Max needs videos that are at least 10 seconds long.</li>
    </ol>
    <p class="help">Videos are not uploaded to this site: you only choose existing YouTube videos. Tool names: upload_link, recent_uploads.</p>
</section>

<section class="card" id="create">
    <h2>Create a campaign</h2>
    <p>Claude can prepare a new <strong>Search</strong> campaign: name, daily budget, bidding, location, keywords and text ads. The campaign is created <strong>paused</strong>.
        By default it runs on Google Search only (no partners or Display) in the area you name. You switch it on yourself in Google Ads when you are happy.</p>
    <h3>Scenario 1: a new search campaign</h3>
    <ol>
        <li>Say what you sell, where, and your daily budget: "I run a bakery in Poznan. Prepare a search campaign for fresh wedding cakes, budget 20 per day, within 15 km of the city."</li>
        <li>Ask for ideas first if you want: "Give me keyword ideas for wedding cakes in Poznan."</li>
        <li>Claude proposes ad groups, keywords, negative keywords and ad texts. Ask for changes until you like them.</li>
        <li>Claude prepares the plan. Read it carefully: budget, location, keywords, texts, and that the campaign starts paused.</li>
        <li>Open the plan page and approve it.</li>
        <li>Open Google Ads, check the campaign once, and enable it when ready.</li>
        <li>After a few days ask: "Run a health check on the new campaign" and "Show me the search terms."</li>
    </ol>
    <h3>Scenario 2: a campaign with images (Performance Max)</h3>
    <ol>
        <li>Upload your photos (see "Photos and videos"), and say "I uploaded the images".</li>
        <li>Ask: "Show me my Performance Max campaigns and their asset groups."</li>
        <li>Say: "Add these images and a logo to the asset group Summer, and write five headlines and two descriptions for it." Add YouTube videos the same way if you have them.</li>
        <li>Optionally add audience hints: "Add search themes: wedding cakes, birthday cakes."</li>
        <li>Review the plan, approve it, and later ask for the Performance Max report to see which assets work.</li>
    </ol>
    <h3>Scenario 3: a weekly account review</h3>
    <ol>
        <li>"Give me an overview of the account and a list of campaigns for the last 7 days."</li>
        <li>"Run a health check on every active campaign and list the problems by importance."</li>
        <li>"Show me search terms with cost and no conversions, and suggest negative keywords."</li>
        <li>"Show impression share and tell me whether budget or ad rank limits us."</li>
        <li>"List Google's recommendations and tell me which make sense."</li>
        <li>"What changed in the account in the last 7 days?"</li>
        <li>Approve the plans you agree with (for example negative keywords). Ask Claude to summarise what was decided.</li>
    </ol>
</section>

<section class="card" id="conversions">
    <h2>Conversions and tracking</h2>
    <p>A <em>conversion</em> is a valuable action, such as a form sent or a call. Without it, Google cannot learn what works.</p>
    <ul>
        <li><strong>See what you track</strong>: the list of conversion actions with their status and whether they count towards your results, plus your account tracking settings.</li>
        <li><strong>Create an action</strong>: a website lead form, phone calls from ads, or an action for importing clicks.
            If a "Website lead form" action already exists, Claude gives you its tag details (the AW- ID and label) to put on your site instead of creating a duplicate.</li>
        <li><strong>Choose what counts</strong>: which actions are primary (used for bidding) or secondary, and which goals are used by one campaign or by the whole account.</li>
        <li><strong>GA4 key events</strong>: see which Google Analytics events are marked as key events, get warnings about automatic events that should not be, and mark or unmark events (needs Analytics edit permission).</li>
        <li><strong>Offline conversions</strong>: sales or leads that happened later can be sent back to Google Ads from your landing-page leads (only leads with a Google click ID and the visitor's consent, clicks younger than 90 days, never twice).</li>
        <li><strong>Landing page "lp2" checks</strong>: if you use the matching landing page, Claude can check that it keeps the Google click IDs, has the tag, and count its leads (never names or contact details).</li>
    </ul>
    <p class="help">Tool names: list_conversion_actions, get_conversion_tag, prepare_conversion_action, prepare_conversion_goals, ga4_key_events, prepare_ga4_key_events,
        prepare_offline_conversions, lp2_leads, verify_lp2. The lp2 and offline features need extra setup by the server owner.</p>
</section>

<section class="card" id="safety">
    <h2>Safety and limits</h2>
    <ul>
        <li><strong>Nothing changes without your approval.</strong> Plans wait for you on the plan page. Approving needs you to be logged in here.
            Claude cannot approve for you (unless the server owner switched on automatic execution, which is off by default and not recommended).</li>
        <li><strong>Budget limit</strong>: a plan cannot set a daily budget above the limit in the server settings (30 per day by default).</li>
        <li><strong>Campaigns start paused.</strong> The plugin never switches a campaign on.</li>
        <li><strong>Checked first</strong>: Google validates every plan before it is saved or shown.</li>
        <li><strong>Read or write access per client</strong>: a "read-only" client can look but never prepare changes. Use read-only for people who should only see reports.</li>
        <li><strong>Your connections</strong>: in <a href="<?= e(url('/connections')) ?>">Connections</a> you can revoke a connection or delete a client at any time.
            Permanent tokens expire after a while and can be renewed there.</li>
        <li><strong>Records</strong>: every plan and its result is kept, so you can ask "What did you change?" or undo it.</li>
        <li><strong>You remain responsible.</strong> Check each plan before you approve it. See the disclaimer in the <a href="<?= e(url('/about')) ?>">About</a> tab.</li>
    </ul>
</section>

<section class="card" id="trouble">
    <h2>Troubleshooting</h2>
    <h3>Claude cannot connect to the plugin</h3>
    <ol>
        <li>Check that the connector in Claude uses the exact address shown in Connections, and that the Client ID and secret are correct.
            If you lost the secret, delete the client and add a new one.</li>
        <li>Check the <a href="<?= e(url('/logs')) ?>">Logs</a> tab with the "Failed" or "Connections" filter. Failed sign-ins and token problems are listed there with the time and a short reason.</li>
        <li>If your client was unused for a long time or its permanent token expired, renew or revoke it in Connections, then connect again.</li>
        <li>In Claude Code, run <code>/mcp</code> and choose Authenticate again.</li>
    </ol>
    <h3>Tools say the Google token expired or access was refused</h3>
    <p>Open <a href="<?= e(url('/connections')) ?>">Connections</a>, disconnect the Google account and connect it again, ticking all permissions on Google's screen.</p>
    <h3>A plan link does not open or says it is invalid</h3>
    <p>Plan links last 24 hours. Ask Claude to open the plan again (it creates a fresh link). If it asks you to log in, request the login link and use the newest e-mail.</p>
    <h3>I do not get the login e-mail</h3>
    <p>Check the spam folder. The e-mail settings are in the <a href="<?= e(url('/settings')) ?>">Settings</a> tab; use "Send test e-mail" there.</p>
    <h3>Something looks wrong</h3>
    <p>Open <a href="<?= e(url('/logs')) ?>">Logs</a>. Errors are marked in red. You can also ask Claude: "Show me the recent server events and errors."
        If you ask for help, send the time and the line from Logs (secrets are hidden automatically).</p>
</section>
