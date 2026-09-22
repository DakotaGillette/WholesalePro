import { render } from 'preact';
import { App } from './components/App';
import { EmailFlow } from './components/flow/EmailFlow';
import './style.css';

const boot = window.protechEditor;
const emailRoot = document.getElementById( 'protech-email-root' );
const templateRoot = document.getElementById( 'protech-editor-root' );

if ( boot && 'email' === boot.mode && emailRoot ) {
	render( <EmailFlow boot={ boot } />, emailRoot );
} else if ( boot && templateRoot ) {
	render( <App boot={ boot } />, templateRoot );
}
