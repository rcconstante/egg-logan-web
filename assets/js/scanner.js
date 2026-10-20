var scannerStream = null;
var isScanning = false;

function initScanner(videoEl, canvasEl, callback) {
    var video = document.getElementById(videoEl);
    var canvas = document.getElementById(canvasEl);
    if (!video || !canvas) { if (callback) callback({error: 'Elements not found'}); return; }

    return {
        start: async function() {
            try {
                scannerStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: 640, height: 480 }
                });
                video.srcObject = scannerStream;
                isScanning = true;
                scanLoop(video, canvas, callback);
            } catch (err) {
                if (callback) callback({error: 'Camera access denied'});
            }
        },
        stop: function() {
            if (scannerStream) { scannerStream.getTracks().forEach(function(t) { t.stop(); }); scannerStream = null; }
            isScanning = false;
        }
    };
}

function scanLoop(video, canvas, callback) {
    if (!isScanning) return;
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        try {
            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            if (typeof jsQR !== 'undefined') {
                var code = jsQR(imageData.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
                if (code && code.data) {
                    isScanning = false;
                    if (callback) callback({data: code.data});
                    return;
                }
            }
        } catch(e) {}
    }
    requestAnimationFrame(function() { scanLoop(video, canvas, callback); });
}
