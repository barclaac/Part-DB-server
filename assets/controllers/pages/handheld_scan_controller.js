import {Controller} from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */
export default class extends Controller {

    _scanner = null;

    connect(event) {
	console.log('Controller connected')
    }
    
    async onConnectScanner(event) {
	console.log('Connect to barcode reader')
	device = await navigator.serial.requestPort({ filters: [{ usbVendorId: 0x03f0, usbDeviceId: 0x0339 }] })
	await device.open({baudRate: 9600})
	
	console.log(device)
	reader = device.readable.getReader()
	decoder = new TextDecoder();
	var barcodeBuffer = '';
	while (true) {
	    const { value, done } = await reader.read();
            if (done) {
                reader.releaseLock();
                break;
            }
            //console.log(value);
            //console.log(decoder.decode(value));
            partial = decoder.decode(value);
            barcodeBuffer += partial
            end = false
            endidx = partial.indexOf('\x1e\x04');
            if (endidx != -1) {
                end = true;
            } else {
                endidx = partial.indexOf('\r');
                if (endidx != -1) {
                    end = true;
                }
            }
	    
            if (end) {
                // Decode the barcode
                console.log(barcodeBuffer)
                start = barcodeBuffer.indexOf('[)>')
                if (start == -1) {
                    console.log('badly formed barcode')
                } else {
		    // Post this back to the server
		    document.getElementById('handheld_scanner_dialog_barcode').value = barcodeBuffer;
		    form = document.getElementById('handheld_dialog_form');
		    form.requestSubmit()
		}	    
                barcodeBuffer = '';
            }
            console.log(end)
	}
    }
}
