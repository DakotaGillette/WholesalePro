/**
 * "Schedule it" on the Send step (3.10.0). Dates and times are the site's
 * own clock ('YYYY-MM-DD' and 'HH:MM'), never the browser's, because the
 * server reads them in the site's timezone. These only shape the choices
 * and catch an obvious past time; the server has the final say.
 */

/** Every half hour of the day, as value 'HH:MM' and a 12-hour label. */
export function timeOptions(): Array< { value: string; label: string } > {
	const out: Array< { value: string; label: string } > = [];

	for ( let minutes = 0; minutes < 24 * 60; minutes += 30 ) {
		const h = Math.floor( minutes / 60 );
		const m = minutes % 60;
		const value = `${ pad( h ) }:${ pad( m ) }`;
		const suffix = h < 12 ? 'am' : 'pm';
		const hour12 = 0 === h % 12 ? 12 : h % 12;

		out.push( { value, label: `${ hour12 }:${ pad( m ) } ${ suffix }` } );
	}

	return out;
}

/** Tomorrow at 8:00 am on the site's clock, a sensible first suggestion. */
export function defaultSchedule( siteNow: string ): { date: string; time: string } {
	const [ date ] = siteNow.split( ' ' );
	const [ y, mo, d ] = date.split( '-' ).map( Number );
	const next = new Date( Date.UTC( y, mo - 1, d + 1 ) );

	return { date: `${ next.getUTCFullYear() }-${ pad( next.getUTCMonth() + 1 ) }-${ pad( next.getUTCDate() ) }`, time: '08:00' };
}

/** Whether a date and time are later than the site's "now", compared as the site-clock strings they are. */
export function isFuture( date: string, time: string, siteNow: string ): boolean {
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) || ! /^\d{2}:\d{2}$/.test( time ) ) {
		return false;
	}

	return `${ date } ${ time }` > siteNow;
}

/** "Wednesday, September 23 at 8:00 am" for the confirm dialog. */
export function describeSchedule( date: string, time: string ): string {
	const [ y, mo, d ] = date.split( '-' ).map( Number );
	const day = new Date( Date.UTC( y, mo - 1, d ) ).toLocaleDateString( undefined, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' } );
	const label = timeOptions().find( ( t ) => t.value === time )?.label ?? time;

	return `${ day } at ${ label }`;
}

function pad( n: number ): string {
	return String( n ).padStart( 2, '0' );
}
