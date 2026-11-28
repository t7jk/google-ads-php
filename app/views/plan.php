<?php /** @var string $html Ready, already escaped HTML of the plan preview (Plans\PlanRenderer) */ ?>
<style>
.plan{--line:#d5d9e2;--muted:#5a6275;--ok:#14663a;--ok-bg:#e6f6ea;--bad:#b3261e;--bad-bg:#fde8e8;--warn-bg:#fff6dd;--warn-line:#c99a00;--info-bg:#eef2fb;--accent:#1a56c4;--on-accent:#fff}
@media (prefers-color-scheme:dark){.plan{--line:#2d3342;--muted:#a3a9b8;--ok:#81c995;--ok-bg:#16301f;--bad:#f28b82;--bad-bg:#3a1c1a;--warn-bg:#3a3017;--warn-line:#c99a00;--info-bg:#1f2635;--accent:#8ab4f8;--on-accent:#0b1b3a}}
.plan h1{font-size:18px;margin:0 0 12px}
.plan h2{font-size:14px;margin:16px 0 6px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
.plan table{width:100%;border-collapse:collapse;margin-bottom:8px}
.plan th,.plan td{text-align:left;vertical-align:top;padding:6px 8px;border-bottom:1px solid var(--line);overflow-wrap:break-word}
.plan th{font-size:12px;color:var(--muted)}
.plan .add td{background:var(--ok-bg)}.plan .remove td,.plan .danger td{background:var(--bad-bg)}.plan .change td{background:var(--warn-bg)}.plan .info td{background:var(--info-bg)}
.plan .sub,.plan .exp{color:var(--muted);font-size:13px}
.plan .warn{background:var(--warn-bg);border-left:4px solid var(--warn-line);padding:8px 10px;margin:6px 0}
.plan .err{background:var(--bad-bg);border-left:4px solid var(--bad);padding:8px 10px;margin:6px 0}
.plan .okbox{background:var(--ok-bg);color:var(--ok);border-left:4px solid var(--ok);padding:8px 10px;margin:12px 0;font-weight:600}
.plan button{font:inherit;font-weight:600;width:100%;min-height:44px;padding:12px;border:0;border-radius:8px;background:var(--accent);color:var(--on-accent);margin-top:12px;cursor:pointer}
.plan button:focus-visible{outline:3px solid #f5a623;outline-offset:2px}
.plan input[type=email]{font:inherit;width:100%;box-sizing:border-box;padding:10px;border:1px solid var(--line);border-radius:8px;background:transparent;color:inherit}
.plan button:disabled{opacity:.5;cursor:default}
</style>
<?= $html ?>
