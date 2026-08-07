<?php
/**
 * Default regions for the state field: US states and territories.
 *
 * One country's worth, because the field has no country to key off — it is a
 * standalone control, not the second half of a country/region pair. Sites
 * outside the US replace this from the Regions option on the settings screen,
 * which is why the list is a default rather than a bundled world table.
 *
 * WooCommerce, when active, supplies its own regions for the store's base
 * country ahead of this.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	'AL' => __( 'Alabama', 'wp-custom-meta-box' ),
	'AK' => __( 'Alaska', 'wp-custom-meta-box' ),
	'AZ' => __( 'Arizona', 'wp-custom-meta-box' ),
	'AR' => __( 'Arkansas', 'wp-custom-meta-box' ),
	'CA' => __( 'California', 'wp-custom-meta-box' ),
	'CO' => __( 'Colorado', 'wp-custom-meta-box' ),
	'CT' => __( 'Connecticut', 'wp-custom-meta-box' ),
	'DE' => __( 'Delaware', 'wp-custom-meta-box' ),
	'DC' => __( 'District of Columbia', 'wp-custom-meta-box' ),
	'FL' => __( 'Florida', 'wp-custom-meta-box' ),
	'GA' => __( 'Georgia', 'wp-custom-meta-box' ),
	'HI' => __( 'Hawaii', 'wp-custom-meta-box' ),
	'ID' => __( 'Idaho', 'wp-custom-meta-box' ),
	'IL' => __( 'Illinois', 'wp-custom-meta-box' ),
	'IN' => __( 'Indiana', 'wp-custom-meta-box' ),
	'IA' => __( 'Iowa', 'wp-custom-meta-box' ),
	'KS' => __( 'Kansas', 'wp-custom-meta-box' ),
	'KY' => __( 'Kentucky', 'wp-custom-meta-box' ),
	'LA' => __( 'Louisiana', 'wp-custom-meta-box' ),
	'ME' => __( 'Maine', 'wp-custom-meta-box' ),
	'MD' => __( 'Maryland', 'wp-custom-meta-box' ),
	'MA' => __( 'Massachusetts', 'wp-custom-meta-box' ),
	'MI' => __( 'Michigan', 'wp-custom-meta-box' ),
	'MN' => __( 'Minnesota', 'wp-custom-meta-box' ),
	'MS' => __( 'Mississippi', 'wp-custom-meta-box' ),
	'MO' => __( 'Missouri', 'wp-custom-meta-box' ),
	'MT' => __( 'Montana', 'wp-custom-meta-box' ),
	'NE' => __( 'Nebraska', 'wp-custom-meta-box' ),
	'NV' => __( 'Nevada', 'wp-custom-meta-box' ),
	'NH' => __( 'New Hampshire', 'wp-custom-meta-box' ),
	'NJ' => __( 'New Jersey', 'wp-custom-meta-box' ),
	'NM' => __( 'New Mexico', 'wp-custom-meta-box' ),
	'NY' => __( 'New York', 'wp-custom-meta-box' ),
	'NC' => __( 'North Carolina', 'wp-custom-meta-box' ),
	'ND' => __( 'North Dakota', 'wp-custom-meta-box' ),
	'OH' => __( 'Ohio', 'wp-custom-meta-box' ),
	'OK' => __( 'Oklahoma', 'wp-custom-meta-box' ),
	'OR' => __( 'Oregon', 'wp-custom-meta-box' ),
	'PA' => __( 'Pennsylvania', 'wp-custom-meta-box' ),
	'RI' => __( 'Rhode Island', 'wp-custom-meta-box' ),
	'SC' => __( 'South Carolina', 'wp-custom-meta-box' ),
	'SD' => __( 'South Dakota', 'wp-custom-meta-box' ),
	'TN' => __( 'Tennessee', 'wp-custom-meta-box' ),
	'TX' => __( 'Texas', 'wp-custom-meta-box' ),
	'UT' => __( 'Utah', 'wp-custom-meta-box' ),
	'VT' => __( 'Vermont', 'wp-custom-meta-box' ),
	'VA' => __( 'Virginia', 'wp-custom-meta-box' ),
	'WA' => __( 'Washington', 'wp-custom-meta-box' ),
	'WV' => __( 'West Virginia', 'wp-custom-meta-box' ),
	'WI' => __( 'Wisconsin', 'wp-custom-meta-box' ),
	'WY' => __( 'Wyoming', 'wp-custom-meta-box' ),
	'AS' => __( 'American Samoa', 'wp-custom-meta-box' ),
	'GU' => __( 'Guam', 'wp-custom-meta-box' ),
	'MP' => __( 'Northern Mariana Islands', 'wp-custom-meta-box' ),
	'PR' => __( 'Puerto Rico', 'wp-custom-meta-box' ),
	'VI' => __( 'U.S. Virgin Islands', 'wp-custom-meta-box' ),
);
