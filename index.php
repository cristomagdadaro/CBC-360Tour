<!DOCTYPE html>
<html lang="en">

<head>
	<title>DA-CBC Virtual Tour</title>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta name="viewport" id="metaViewport"
		content="user-scalable=no, initial-scale=1, width=device-width, viewport-fit=cover"
		data-tdv-general-scale="1" />
	<meta name="description" content="Virtual Tour: DA-Crop Biotechnology Center" />
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="default">
	<script src="lib/tdvplayer.js?v=1731920986256"></script>
	<link rel="shortcut icon" href="favicon.ico?v=1731920986256">
	<link rel="icon" sizes="48x48 32x32 16x16" href="favicon.ico?v=1731920986256">
	<link rel="apple-touch-icon" type="image/png" sizes="180x180" href="misc/icon180.png?v=1731920986256">
	<link rel="icon" type="image/png" sizes="16x16" href="misc/icon16.png?v=1731920986256">
	<link rel="icon" type="image/png" sizes="32x32" href="misc/icon32.png?v=1731920986256">
	<link rel="icon" type="image/png" sizes="192x192" href="misc/icon192.png?v=1731920986256">
	<link rel="manifest" href="manifest.json?v=1731920986256">
	<meta name="msapplication-TileColor" content="#666666">
	<meta name="msapplication-config" content="browserconfig.xml">
	<link rel="preload" href="misc/icon150.png" as="image" />
	<link rel="preload" href="locale/en.txt?v=1731920986256" as="fetch" crossorigin="anonymous" />
	<link rel="preload" href="script.js?v=1731920986256" as="script" />
	<link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/r/3/0_0.jpg?v=1731920986256"
		as="image" />
	<link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/l/3/0_0.jpg?v=1731920986256"
		as="image" />
	<link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/u/3/0_0.jpg?v=1731920986256"
		as="image" />
	<link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/d/3/0_0.jpg?v=1731920986256"
		as="image" />
	<link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/f/3/0_0.jpg?v=1731920986256"
		as="image" />
	<link rel="preload" href="media/panorama_0980CA67_24E0_DFA6_41A8_442675557741_0/b/3/0_0.jpg?v=1731920986256"
		as="image" />
	<meta name="description" content="Virtual Tour" />
	<meta name="theme-color" content="#666666" />
	<script src="script.js?v=1731920986256"></script>
	<style type="text/css">
		html,
		body {
			height: 100%;
			width: 100%;
			height: 100vh;
			width: 100vw;
			margin: 0;
			padding: 0;
			overflow: hidden;
		}

		.fill-viewport {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			padding: 0;
			margin: 0;
			overflow: hidden;
		}

		#viewer {
			z-index: 1;
		}

		#preloadContainer {
			z-index: 2;
			position: relative;
			width: 100%;
			height: 100%;
			transition: opacity 0.5s;
			-webkit-transition: opacity 0.5s;
			-moz-transition: opacity 0.5s;
			-o-transition: opacity 0.5s;
		}

		#loadingIcon {
			width: 150px;
			height: auto;
    	}

		/* Small screens (phones) */
		@media (max-width: 480px) {
			#loadingIcon {
				width: 32px;
			}

			#loadingMessage {
				font-size: 14px;
			}
		}

		/* Tablets and small laptops */
		@media (min-width: 481px) and (max-width: 768px) {
			#loadingIcon {
				width: 64px;
			}

			#loadingMessage {
				font-size: 16px;
			}
		}

		/* Medium screens (normal laptops/desktops) */
		@media (min-width: 769px) and (max-width: 1200px) {
			#loadingIcon {
				width: 150px;
			}
			#loadingMessage {
				font-size: 20px;
			}
		}

		/* Large screens (big desktops, 4K displays) */
		@media (min-width: 1201px) {
			#loadingIcon {
				width: 180px;
				font-size: 24px;
			}

			#loadingMessage {
				font-size: 24px;
			}
		}
	</style>
	<link rel="stylesheet" href="fonts.css?v=1731920986256">
</head>

<body>
<div id="preloadContainer" style="background: radial-gradient(circle, rgba(20, 80, 70, 0.8) 0%, #08322C 70%); display: flex; justify-content: center; align-items: center; height: 100vh; flex-direction: column;">
    <img id="loadingIcon" src="/misc/icon150.png"  alt="DA-CBC Logo"/>
    <span id="loadingMessage" style="letter-spacing: 0; color: #ffffff; font-family: Arial, Helvetica, sans-serif; text-align: center; margin-top:5px;">
            Loading virtual tour. Please wait...
        </span>
</div>

<!-- Tour Viewer -->
<div id="viewer" class="fill-viewport"></div>

<!-- Global Visit Counter -->
<div id="visitCounter" style="
        position: fixed;
        bottom: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.6);
        color: white;
        padding: 6px 10px;
        border-radius: 8px;
        font-family: Arial, sans-serif;
        font-size: 14px;
        z-index: 9999;">
    Loading total visits...
</div>

</body>

<script>
    fetch(`https://pin.philrice.gov.ph/api/cbc360tour/visitor/counter`)
        .then(res => res.json())
        .then(data => {
            document.getElementById('visitCounter').innerText = `Visited ${data.value} time(s)`;
        })
        .catch(err => {
            document.getElementById('visitCounter').innerText = 'Visit count unavailable';
            console.error(err);
        });
</script>