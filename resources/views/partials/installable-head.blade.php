{{-- Application installable sur l'écran d'accueil (téléphone et ordinateur). --}}
<link rel="manifest" href="{{ asset_v('manifest.json') }}">
<meta name="theme-color" content="#0b2a6b">
<link rel="apple-touch-icon" href="{{ asset_v('images/app/apple-touch-icon.png') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Cable TV">
{{-- Les textes du bouton « Installer l'application », dans la langue de la page. --}}
<script>window.installLabels = {!! json_encode(["install" => __("Installer l'application"), "installed" => __("Application installée ✓"), "preparing" => __("Préparation de l'installation…"), "retry" => __("Réessayez dans un instant")], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};</script>
