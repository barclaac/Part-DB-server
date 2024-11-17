import {Controller} from "@hotwired/stimulus";

export default class extends Controller {

    _scanner = null;

    connect() {
	console.log('Connect to barcode reader');
    }
}
