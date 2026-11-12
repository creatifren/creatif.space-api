{{-- Brand pack v1.3 tokens. Text on orange / lavender is always ink. --}}
<style>
@font-face { font-family: 'Lora'; font-style: normal; font-weight: normal; src: url('{{ $fonts['lora'] }}') format('truetype'); }
@font-face { font-family: 'Lora'; font-style: normal; font-weight: bold; src: url('{{ $fonts['lora'] }}') format('truetype'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: normal; src: url('{{ $fonts['inter'] }}') format('truetype'); }
@font-face { font-family: 'Inter'; font-style: normal; font-weight: bold; src: url('{{ $fonts['inter'] }}') format('truetype'); }

body, div, p, h2, table, tr, td, th, span, img { margin: 0; padding: 0; }
html { background-color: #FAFAF8; }
body { font-family: 'Inter'; color: #0F172A; background: #FAFAF8; }
.serif { font-family: 'Lora'; }
.muted { color: #5A6478; }
.label { font-family: 'Inter'; color: #5A6478; text-transform: uppercase; letter-spacing: 0.06em; }
.up { color: #1F3D34; }
.down { color: #A34A3C; }
.flat { color: #5A6478; }
table { border-collapse: collapse; }
.tile { border-radius: 14px; border: 1px solid #ECE9E2; background: #FFFFFF; }
.tile.orange { background: #FF9F43; border-color: #FF9F43; }
.tile.lavender { background: #C4A8FF; border-color: #C4A8FF; }
.tile.orange .label, .tile.lavender .label, .tile.orange .muted, .tile.lavender .muted { color: #0F172A; }
.bar { background: #FF9F43; border-radius: 3px 3px 0 0; }
.thumb { background: #ECE9E2; border-radius: 2mm; overflow: hidden; }
.thumb img { display: block; border-radius: 2mm; }
.rule { border-top: 1px solid #ECE9E2; }
.chip { display: inline-block; border-radius: 999px; background: #ECE9E2; }
.empty { border: 1px dashed #ECE9E2; border-radius: 14px; background: #FFFFFF; text-align: center; color: #5A6478; }
</style>
