import { render } from 'preact';
import { App } from './components/App';
import './style.css';

const mount = document.getElementById( 'protech-editor-root' );

if ( mount && window.protechEditor ) {
	render( <App boot={ window.protechEditor } />, mount );
}
