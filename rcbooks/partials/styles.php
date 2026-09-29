<style>
  :root{
    --bg:#0b1220;
    --card:#0f1a2e;
    --card2:#0d1729;
    --text:#e6edf7;
    --muted:#9fb0cc;
    --line:rgba(255,255,255,.08);
    --accent:#5dd6ff;
    --good:#22c55e;
    --warn:#f59e0b;
    --bad:#ef4444;
    --shadow:0 10px 30px rgba(0,0,0,.35);
    --radius:16px;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
    background:radial-gradient(1200px 700px at 20% -10%, rgba(93,214,255,.14), transparent 55%),
               radial-gradient(900px 600px at 90% 10%, rgba(34,197,94,.10), transparent 55%),
               var(--bg);
    color:var(--text);
  }
  a{color:inherit;text-decoration:none}
  .app{
    display:grid;
    grid-template-columns: 320px 1fr 360px;
    height:100vh;
    gap:14px;
    padding:14px;
  }
  .panel{
    background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
    border:1px solid var(--line);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    overflow:hidden;
    display:flex;
    flex-direction:column;
    min-height:0;
  }
  .hdr{
    padding:14px 14px 10px 14px;
    border-bottom:1px solid var(--line);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
  }
  .hdr h3{margin:0;font-size:14px;letter-spacing:.4px;text-transform:uppercase;color:var(--muted)}
  .body{padding:12px;overflow:auto;min-height:0}
  .btn{
    background:rgba(93,214,255,.12);
    color:var(--text);
    border:1px solid rgba(93,214,255,.30);
    padding:8px 10px;
    border-radius:12px;
    cursor:pointer;
    font-weight:600;
    font-size:13px;
  }
  .btn:hover{filter:brightness(1.1)}
  .btn2{
    background:rgba(255,255,255,.06);
    border:1px solid var(--line);
  }
  .btnDanger{
    background:rgba(239,68,68,.12);
    border:1px solid rgba(239,68,68,.35);
  }
  .row{display:flex;gap:10px;align-items:center}
  .field{
    width:100%;
    background:rgba(255,255,255,.06);
    border:1px solid var(--line);
    border-radius:12px;
    padding:10px 12px;
    color:var(--text);
    outline:none;
  }
  textarea.field{min-height:90px;resize:vertical}
  .list{display:flex;flex-direction:column;gap:8px}
  .item{
    padding:10px 12px;
    border-radius:14px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.04);
  }
  .item:hover{background:rgba(255,255,255,.06)}
  .item .t{font-weight:700}
  .item .s{color:var(--muted);font-size:12px;margin-top:4px}
  .pill{
    display:inline-flex;align-items:center;gap:8px;
    padding:6px 10px;border-radius:999px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.05);
    color:var(--muted);
    font-size:12px;
  }
  .muted{color:var(--muted)}
  .split{display:flex;flex-direction:column;gap:12px}
  .editorWrap{
    background:rgba(255,255,255,.04);
    border:1px solid var(--line);
    border-radius:var(--radius);
    overflow:hidden;
    min-height:0;
    display:flex;
    flex-direction:column;
  }
  .topbar{
    padding:10px 12px;
    border-bottom:1px solid var(--line);
    display:flex;justify-content:space-between;align-items:center;gap:10px;
  }
  .titleBig{font-size:18px;font-weight:800;margin:0}
  .status{font-size:12px;color:var(--muted)}
  #editor{
    height: calc(100vh - 14px - 14px - 14px - 70px);
    background:#fff;
    color:#111;
  }
  .ql-toolbar.ql-snow{
    border:0;
    border-bottom:1px solid rgba(0,0,0,.08);
    background:rgba(255,255,255,.96);
  }
  .ql-container.ql-snow{border:0}
  .emptyState{
    padding:18px;
    border:1px dashed var(--line);
    border-radius:var(--radius);
    color:var(--muted);
    background:rgba(255,255,255,.03);
  }
  .modalBg{
    position:fixed;inset:0;background:rgba(0,0,0,.55);
    display:none;align-items:center;justify-content:center;padding:18px;
  }
  .modal{
    width:min(720px, 100%);
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border:1px solid var(--line);
    border-radius:18px;
    box-shadow:var(--shadow);
    overflow:hidden;
  }
  .modal .hdr{border-bottom:1px solid var(--line)}
  .modal .body{padding:14px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width: 1100px){
    .app{grid-template-columns: 1fr; height:auto}
    #editor{height:420px}
  }
</style>
