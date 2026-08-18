<?php
declare(strict_types=1);

/**
 * Gestaltungssystem „Prüfprotokoll" — ein CSS-Block, an einer Stelle.
 *
 * Kein Framework, keine Webfonts, kein CDN, kein Build-Schritt. Das ist keine Askese:
 * Die Seite muss auf web81 als einzelne PHP-Datei laufen und im Zweifel als Anlage zu
 * einem Schriftsatz gedruckt werden. Alles, was ein Netz braucht, wäre ein Risiko.
 *
 * Zwei Regeln, die überall gelten:
 * * **Farbe trägt nie allein.** Jeder Status hat Zeichen UND Wort (✓ gesund, ! Anlauf,
 *   × fehlgeschlagen). Das ist zugleich der Barrierefreiheits-Standard und die
 *   Voraussetzung dafür, dass ein Schwarzweißdruck lesbar bleibt.
 * * **Rot ist echten Vorfällen vorbehalten.** Ein System, das ab Tag 1 überall rot
 *   leuchtet, macht Rot an Tag 30 bedeutungslos — bei einem Compliance-Werkzeug ist
 *   Alarmmüdigkeit gefährlich.
 */
function y5x_stil(): void
{
    ?>
<style>
:root{
  --tinte:#1d221e; --papier:#f4f4f0; --karte:#fff; --linie:#dcdcd4; --linie-stark:#b9b9ae;
  --tanne:#14231b; --tanne-hell:#2e5240;
  --ok:#2f6b3a; --ok-flaeche:#e8f1e9; --warn:#8a5200; --warn-flaeche:#f8ecd7;
  --vorfall:#a8231b; --vorfall-flaeche:#f9e7e5; --neutral:#6b6b64;
  --aktion:#c46a00; --aktion-flaeche:#f7e3c8;
  --referenz:#a8231b; --vorstufe:#6d4a9e; --preis:#1d3a2b; --fenster:#2e5240;
  --mono:ui-monospace,"SF Mono","Cascadia Code",Consolas,"Liberation Mono",monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--papier);color:var(--tinte);
     font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--tanne-hell)}
code,.mono{font-family:var(--mono);font-size:.94em}

header{background:var(--tanne);color:#eef2ee;padding:.65rem 1.2rem;
       display:flex;align-items:center;gap:1.1rem;flex-wrap:wrap}
header .marke{font-weight:700;letter-spacing:.02em}
header .marke small{display:block;font-weight:400;color:#a9bcae;font-size:.72rem;
                    letter-spacing:.14em;text-transform:uppercase}
header nav{display:flex;gap:.15rem;margin-right:auto}
header nav a{color:#cfdcd2;text-decoration:none;padding:.35rem .7rem;border-radius:5px}
header nav a[aria-current]{background:rgba(255,255,255,.14);color:#fff;font-weight:600}
header nav a:hover{background:rgba(255,255,255,.09)}
.chipzeile{display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;font-size:.8rem}
.chip{border:1px solid rgba(255,255,255,.35);border-radius:99px;padding:.12rem .6rem;
      color:#dfe8e1;white-space:nowrap}
.chip.trocken{background:#f2c14e;border-color:#f2c14e;color:#241d05;font-weight:700}
.chip.scharf{background:var(--vorfall);border-color:var(--vorfall);color:#fff;font-weight:700}

main{max-width:1180px;margin:0 auto;padding:1.1rem 1.2rem 3rem}
h2{font-size:.78rem;letter-spacing:.16em;text-transform:uppercase;color:var(--neutral);
   margin:1.9rem 0 .55rem;font-weight:700}
h2:first-of-type{margin-top:1.1rem}

.plakette{background:var(--karte);border:1px solid var(--linie);border-radius:8px;
          padding:.75rem 1rem;display:flex;gap:.8rem;align-items:flex-start}
.plakette .punkt{width:.85rem;height:.85rem;border-radius:50%;margin-top:.28rem;flex:none;
                 background:var(--ok)}
.plakette.warnstufe .punkt{background:var(--warn)} .plakette.warnstufe{border-left:4px solid var(--warn)}
.plakette.vorfallstufe .punkt{background:var(--vorfall)} .plakette.vorfallstufe{border-left:4px solid var(--vorfall)}
.plakette.okstufe{border-left:4px solid var(--ok)}
.plakette h1{font-size:1.02rem;margin:0 0 .15rem;font-weight:700}
.plakette p{margin:0;color:var(--neutral)}

.handeln{list-style:none;margin:.5rem 0 0;padding:0}
.handeln li{background:var(--karte);border:1px solid var(--linie);border-radius:8px;
            padding:.6rem .9rem;margin-top:.45rem;display:flex;gap:.7rem;flex-wrap:wrap;
            align-items:baseline}
.handeln .stufe{font-size:.72rem;font-weight:700;letter-spacing:.08em;border-radius:4px;
                padding:.1rem .45rem;text-transform:uppercase;flex:none}
.stufe.warn{background:var(--warn-flaeche);color:var(--warn)}
.stufe.vorfall{background:var(--vorfall-flaeche);color:var(--vorfall)}
.stufe.einrichtung{background:#e6edf4;color:#20496b}
.handeln .was{flex:1 1 24rem}
.handeln .tu{white-space:nowrap}

.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:.6rem}
.kpi>div{background:var(--karte);border:1px solid var(--linie);border-radius:8px;padding:.65rem .8rem}
.kpi b{display:block;font:600 1.65rem/1.15 var(--mono);font-variant-numeric:tabular-nums}
.kpi .label{font-weight:600;font-size:.85rem}
.kpi .def{color:var(--neutral);font-size:.76rem;line-height:1.35;display:block;margin-top:.15rem}
.kpi>div.risiko{border-left:4px solid var(--vorfall)} .kpi .risiko b{color:var(--vorfall)}
.kpi>div.risiko.leer{border-left:4px solid var(--ok)} .kpi .risiko.leer b{color:var(--ok)}

.tabelle{overflow-x:auto;background:var(--karte);border:1px solid var(--linie);border-radius:8px}
table{border-collapse:collapse;width:100%;font-size:.9rem}
th,td{padding:.42rem .6rem;text-align:left;border-top:1px solid var(--linie);vertical-align:top}
thead th{border-top:0;background:#fafaf6;font-size:.72rem;letter-spacing:.07em;
         text-transform:uppercase;color:var(--neutral)}
td.zahl,th.zahl{text-align:right;font-family:var(--mono);font-variant-numeric:tabular-nums}
tbody tr:hover{background:#fbfbf7}
td .sub{display:block;color:var(--neutral);font-size:.78rem;font-family:system-ui;letter-spacing:0}
tr.offen td{background:#fbfdf9}

.status{display:inline-flex;align-items:center;gap:.3rem;border-radius:5px;
        padding:.08rem .5rem;font-size:.78rem;font-weight:600;white-space:nowrap}
.status::before{font-family:var(--mono)}
.status.ok{background:var(--ok-flaeche);color:var(--ok)}            .status.ok::before{content:"✓"}
.status.warn{background:var(--warn-flaeche);color:var(--warn)}      .status.warn::before{content:"!"}
.status.vorfall{background:var(--vorfall-flaeche);color:var(--vorfall)} .status.vorfall::before{content:"×"}
.status.aus{background:#ecece6;color:var(--neutral)}                .status.aus::before{content:"–"}
.status.aktion{background:var(--aktion-flaeche);color:var(--aktion)} .status.aktion::before{content:"▲"}
.status.laeuft{background:#e6edf4;color:#20496b}
.status.laeuft::before{content:"◌";animation:puls 1.6s ease-in-out infinite}
.status.einrichtung{background:#e6edf4;color:#20496b}               .status.einrichtung::before{content:"◧"}
@keyframes puls{50%{opacity:.25}}
@media (prefers-reduced-motion:reduce){.status.laeuft::before{animation:none}}

.anlauf{display:inline-block;min-width:8.5rem}
.anlauf .balken{height:5px;background:#e4e4dc;border-radius:3px;overflow:hidden;margin-top:.25rem}
.anlauf .balken i{display:block;height:100%;background:var(--tanne-hell)}
.anlauf small{color:var(--neutral)}

.fussnote{color:var(--neutral);font-size:.8rem;margin-top:.5rem}
.fussnote dt{font-weight:700;float:left;clear:left;margin-right:.4rem}
.fussnote dd{margin:0 0 .2rem 0}

form.suche{display:flex;gap:.45rem;flex-wrap:wrap;align-items:flex-end;margin:.9rem 0 1.2rem}
form.suche label{font-size:.78rem;color:var(--neutral);display:block;margin-bottom:.15rem}
form.suche input,form.suche select{padding:.4rem .55rem;border:1px solid var(--linie-stark);
   border-radius:6px;background:#fff;font:inherit}
form.suche input[name=sku]{font-family:var(--mono);width:11rem}
.knopf{background:var(--tanne-hell);color:#fff;border:0;border-radius:6px;
       padding:.48rem .9rem;font:600 .9rem/1 system-ui;cursor:pointer}
.knopf.sekundaer{background:#fff;color:var(--tanne-hell);border:1px solid var(--tanne-hell)}
a:focus-visible,input:focus-visible,select:focus-visible,.knopf:focus-visible,button:focus-visible
  {outline:2px solid var(--tanne-hell);outline-offset:2px}

.artikelkopf{background:var(--karte);border:1px solid var(--linie);border-radius:8px;
             padding:.85rem 1rem;display:flex;gap:1rem;flex-wrap:wrap;align-items:baseline}
.artikelkopf .sku{font:700 1.25rem/1.2 var(--mono)}
.artikelkopf .meta{color:var(--neutral);font-size:.85rem;margin-left:auto;text-align:right}
.hinweiskasten{border:1px solid var(--linie);border-left:4px solid var(--warn);
  background:var(--karte);border-radius:8px;padding:.65rem .9rem;margin:.7rem 0;color:#3c3c36}
.hinweiskasten b{color:var(--warn)}
.hinweiskasten.ruhig{border-left-color:var(--tanne-hell)} .hinweiskasten.ruhig b{color:var(--tanne-hell)}

.schrieb{background:var(--karte);border:1px solid var(--linie);border-radius:8px;padding:.9rem 1rem}
.schrieb svg{width:100%;height:auto;display:block}
.legende{display:flex;gap:1.1rem;flex-wrap:wrap;font-size:.8rem;color:var(--neutral);margin-top:.5rem}
.legende i{display:inline-block;width:1.5rem;height:0;border-top:3px solid;margin-right:.35rem;
           vertical-align:middle}
.legende .l-preis i{border-color:var(--preis)}
.legende .l-ref i{border-top-style:dashed;border-color:var(--referenz)}
.legende .l-prev i{border-top-style:dotted;border-top-width:4px;border-color:var(--vorstufe)}
.legende .l-fenster i{border:0;height:.8rem;background:var(--fenster);opacity:.14}
.legende .l-aktion i{border:0;height:.8rem;background:var(--aktion);opacity:.22}

.raster{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:.7rem}
.karte{background:var(--karte);border:1px solid var(--linie);border-radius:8px;padding:.75rem .9rem}
.karte h3{margin:0 0 .5rem;font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--neutral)}
.etikett{display:flex;justify-content:space-between;align-items:baseline;
         border-top:1px dashed var(--linie);padding:.4rem 0;gap:.6rem}
.etikett:first-of-type{border-top:0}
.etikett .typ{font-family:var(--mono);font-size:.82rem;color:var(--neutral)}
.etikett .wert{font:600 1.15rem/1 var(--mono);font-variant-numeric:tabular-nums}
.etikett .sub{font-size:.75rem;color:var(--neutral);display:block;text-align:right}
.zustand dt{font-size:.78rem;color:var(--neutral)}
.zustand dd{margin:0 0 .45rem;font-family:var(--mono)}

.stempel{border:2px dashed var(--tanne-hell);border-radius:8px;background:#fbfcfa;padding:.8rem 1rem}
.stempel h3{margin:0 0 .3rem;font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;color:var(--tanne-hell)}
.stempel p{margin:.15rem 0;font-family:var(--mono);font-size:.95rem}
.stempel .quelle{font-family:system-ui;color:var(--neutral);font-size:.78rem}

.blaettern{margin:.6rem 0 1.4rem;display:flex;gap:.25rem;flex-wrap:wrap;align-items:center}
.blaettern a,.blaettern b{display:inline-block;padding:.25rem .55rem;border:1px solid var(--linie-stark);
  border-radius:5px;background:#fff;text-decoration:none;font-family:var(--mono);font-size:.85rem}
.blaettern b{background:var(--tanne-hell);color:#fff;border-color:var(--tanne-hell)}
.blaettern .zaehler{color:var(--neutral);font-size:.82rem;margin-left:.4rem;font-family:system-ui}

.druckkopf{display:none}
@media print{
  header,form.suche,.blaettern,nav{display:none!important}
  body{background:#fff}
  .druckkopf{display:block;border-bottom:2px solid #000;margin-bottom:1rem;padding-bottom:.5rem}
  .druckkopf h1{font:700 1.2rem/1.3 var(--mono);margin:0}
  .druckkopf p{margin:.15rem 0;font-size:.85rem}
  .karte,.tabelle,.schrieb,.stempel,.plakette,.kpi>div,.handeln li{break-inside:avoid;border-color:#999}
}
</style>
    <?php
}
