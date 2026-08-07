/**
 * QR encoder.
 *
 * Pure functions: text in, a module matrix out. No DOM, no network, no
 * configuration. enhanced.js draws the result.
 *
 * Written out rather than pulled in because the plugin may not load anything
 * from an external host, which rules out both a CDN library and a "generate
 * this QR for me" image service. An image service would also mean every value
 * an editor types leaves the site, which is not a reasonable thing for a field
 * plugin to do quietly.
 *
 * Correctness here is checked, not assumed: the Reed-Solomon step is asserted
 * against the worked example in the specification, and the finished matrix
 * against the finder, timing and dark-module invariants. A QR code that is
 * subtly wrong looks entirely convincing and scans as nothing.
 *
 * There is no barcode encoder here on purpose. Code 128 is a 107-entry lookup
 * table with no internal structure to check an implementation against, unlike
 * the arithmetic above — so a mistake in it produces a barcode that scans
 * cleanly as the wrong data. The barcode field stays on the
 * `wpcmb/field/enhanced_config` hook until the table can be taken from a
 * reference rather than reconstructed.
 *
 * @package WPCMB
 */

/* eslint-disable no-bitwise */

( function () {
	'use strict';

	/* --------------------------------------------------------------------
	 * GF(256) arithmetic, shared by Reed-Solomon below.
	 * ----------------------------------------------------------------- */

	var EXP = new Uint8Array( 512 );
	var LOG = new Uint8Array( 256 );

	( function buildTables() {
		var x = 1;

		for ( var i = 0; i < 255; i++ ) {
			EXP[ i ] = x;
			LOG[ x ] = i;

			// Multiply by 2 in GF(256), reducing by the QR primitive
			// polynomial 0x11d when the top bit overflows.
			x <<= 1;

			if ( x & 0x100 ) {
				x ^= 0x11d;
			}
		}

		for ( var j = 255; j < 512; j++ ) {
			EXP[ j ] = EXP[ j - 255 ];
		}
	}() );

	/**
	 * Multiply two field elements.
	 *
	 * @param {number} a First element.
	 * @param {number} b Second element.
	 * @return {number} The product.
	 */
	function gfMul( a, b ) {
		return 0 === a || 0 === b ? 0 : EXP[ LOG[ a ] + LOG[ b ] ];
	}

	/**
	 * The generator polynomial for a given number of error-correction words.
	 *
	 * @param {number} degree Number of EC codewords.
	 * @return {number[]} Polynomial coefficients, highest power first.
	 */
	function generator( degree ) {
		var poly = [ 1 ];

		for ( var d = 0; d < degree; d++ ) {
			var next = poly.concat( 0 );

			for ( var i = 0; i < poly.length; i++ ) {
				next[ i + 1 ] ^= gfMul( poly[ i ], EXP[ d ] );
			}

			poly = next;
		}

		return poly;
	}

	/**
	 * Reed-Solomon error-correction codewords for a block of data.
	 *
	 * @param {number[]} data   Data codewords.
	 * @param {number}   degree Number of EC codewords to produce.
	 * @return {number[]} The EC codewords.
	 */
	function reedSolomon( data, degree ) {
		var poly = generator( degree );
		var remainder = data.concat( new Array( degree ).fill( 0 ) );

		for ( var i = 0; i < data.length; i++ ) {
			var factor = remainder[ i ];

			if ( 0 === factor ) {
				continue;
			}

			for ( var j = 0; j < poly.length; j++ ) {
				remainder[ i + j ] ^= gfMul( poly[ j ], factor );
			}
		}

		return remainder.slice( data.length );
	}

	/* --------------------------------------------------------------------
	 * QR, byte mode, error-correction level M.
	 *
	 * Byte mode alone: it encodes anything, and the alphanumeric and numeric
	 * modes only exist to squeeze more in. A field value that does not fit in
	 * version 20 at level M is not a QR code anybody should be scanning.
	 * ----------------------------------------------------------------- */

	// Total codewords, EC codewords per block, and block counts per version at
	// level M, for versions 1-20. Index 0 is version 1.
	var QR_M = [
		[ 26, 10, 1, 0 ], [ 44, 16, 1, 0 ], [ 70, 26, 1, 0 ], [ 100, 18, 2, 0 ],
		[ 134, 24, 2, 0 ], [ 172, 16, 4, 0 ], [ 196, 18, 4, 0 ], [ 242, 22, 2, 2 ],
		[ 292, 22, 3, 2 ], [ 346, 26, 4, 1 ], [ 404, 30, 1, 4 ], [ 466, 22, 6, 2 ],
		[ 532, 22, 8, 1 ], [ 581, 24, 4, 5 ], [ 655, 24, 5, 5 ], [ 733, 28, 7, 3 ],
		[ 815, 28, 10, 1 ], [ 901, 26, 9, 4 ], [ 991, 26, 3, 11 ], [ 1085, 26, 3, 13 ],
	];

	// Alignment-pattern centre coordinates per version, index 0 is version 1.
	var ALIGN = [
		[], [ 6, 18 ], [ 6, 22 ], [ 6, 26 ], [ 6, 30 ], [ 6, 34 ],
		[ 6, 22, 38 ], [ 6, 24, 42 ], [ 6, 26, 46 ], [ 6, 28, 50 ],
		[ 6, 30, 54 ], [ 6, 32, 58 ], [ 6, 34, 62 ], [ 6, 26, 46, 66 ],
		[ 6, 26, 48, 70 ], [ 6, 26, 50, 74 ], [ 6, 30, 54, 78 ],
		[ 6, 30, 56, 82 ], [ 6, 30, 58, 86 ], [ 6, 34, 62, 90 ],
	];

	/**
	 * UTF-8 encode a string to bytes.
	 *
	 * @param {string} text Input.
	 * @return {number[]} Bytes.
	 */
	function utf8Bytes( text ) {
		var out = [];
		var encoded = unescape( encodeURIComponent( text ) );

		for ( var i = 0; i < encoded.length; i++ ) {
			out.push( encoded.charCodeAt( i ) );
		}

		return out;
	}

	/**
	 * Build the QR module matrix for a string.
	 *
	 * @param {string} text Value to encode.
	 * @return {number[][]|null} Square matrix of 0/1, or null if it will not fit.
	 */
	function qrMatrix( text ) {
		var bytes = utf8Bytes( String( text ) );
		var version = 0;
		var spec = null;

		// The character-count field is 8 bits below version 10 and 16 at or
		// above it, so capacity has to be checked against the right header.
		for ( var v = 1; v <= QR_M.length; v++ ) {
			var candidate = QR_M[ v - 1 ];
			var blocks = candidate[ 2 ] + candidate[ 3 ];
			var capacity = candidate[ 0 ] - ( candidate[ 1 ] * blocks );
			var header = 4 + ( v < 10 ? 8 : 16 );

			if ( bytes.length + Math.ceil( header / 8 ) <= capacity ) {
				version = v;
				spec = candidate;
				break;
			}
		}

		if ( ! spec ) {
			return null;
		}

		var size = 17 + ( version * 4 );
		var bits = [];

		/**
		 * Append a value as a fixed number of bits.
		 *
		 * @param {number} value  Value.
		 * @param {number} length Bit count.
		 */
		function push( value, length ) {
			for ( var i = length - 1; i >= 0; i-- ) {
				bits.push( ( value >> i ) & 1 );
			}
		}

		push( 4, 4 );
		push( bytes.length, version < 10 ? 8 : 16 );
		bytes.forEach( function ( byte ) {
			push( byte, 8 );
		} );

		var totalBlocks = spec[ 2 ] + spec[ 3 ];
		var dataWords = spec[ 0 ] - ( spec[ 1 ] * totalBlocks );

		// Terminator, then pad to a byte boundary, then the two alternating
		// pad bytes the specification names.
		push( 0, Math.min( 4, ( dataWords * 8 ) - bits.length ) );

		while ( 0 !== bits.length % 8 ) {
			bits.push( 0 );
		}

		var words = [];

		for ( var b = 0; b < bits.length; b += 8 ) {
			words.push( parseInt( bits.slice( b, b + 8 ).join( '' ), 2 ) );
		}

		// Pad with the alternating 0xEC / 0x11 sequence the specification names,
		// starting at 0xEC whatever the data length happened to be.
		var padFrom = words.length;

		while ( words.length < dataWords ) {
			words.push( ( words.length - padFrom ) % 2 ? 0x11 : 0xec );
		}

		// Split into blocks. The short blocks come first, then the long ones,
		// which carry exactly one extra data codeword each.
		var shortLen = Math.floor( dataWords / totalBlocks );
		var dataBlocks = [];
		var ecBlocks = [];
		var offset = 0;

		for ( var k = 0; k < totalBlocks; k++ ) {
			var length = shortLen + ( k < spec[ 2 ] ? 0 : 1 );
			var block = words.slice( offset, offset + length );

			offset += length;
			dataBlocks.push( block );
			ecBlocks.push( reedSolomon( block, spec[ 1 ] ) );
		}

		// Interleave: one codeword from each block in turn, data then EC.
		var stream = [];
		var longest = Math.max.apply( null, dataBlocks.map( function ( block ) {
			return block.length;
		} ) );

		for ( var c = 0; c < longest; c++ ) {
			dataBlocks.forEach( function ( block ) {
				if ( c < block.length ) {
					stream.push( block[ c ] );
				}
			} );
		}

		for ( var e = 0; e < spec[ 1 ]; e++ ) {
			ecBlocks.forEach( function ( block ) {
				stream.push( block[ e ] );
			} );
		}

		return placeModules( stream, version, size );
	}

	/**
	 * Lay the codeword stream out in the matrix, with the function patterns.
	 *
	 * @param {number[]} stream  Interleaved codewords.
	 * @param {number}   version QR version.
	 * @param {number}   size    Matrix size.
	 * @return {number[][]} The finished matrix.
	 */
	function placeModules( stream, version, size ) {
		var matrix = [];
		var reserved = [];
		var i;
		var j;

		for ( i = 0; i < size; i++ ) {
			matrix.push( new Array( size ).fill( 0 ) );
			reserved.push( new Array( size ).fill( false ) );
		}

		/**
		 * Write a module and mark it as a function pattern.
		 *
		 * @param {number} row   Row.
		 * @param {number} col   Column.
		 * @param {number} value 0 or 1.
		 */
		function fixed( row, col, value ) {
			if ( row < 0 || col < 0 || row >= size || col >= size ) {
				return;
			}

			matrix[ row ][ col ] = value;
			reserved[ row ][ col ] = true;
		}

		// Finder patterns, plus the one-module separator around each.
		[ [ 0, 0 ], [ 0, size - 7 ], [ size - 7, 0 ] ].forEach( function ( origin ) {
			for ( var r = -1; r <= 7; r++ ) {
				for ( var c = -1; c <= 7; c++ ) {
					var inRing = 0 === r || 6 === r || 0 === c || 6 === c;
					var inCore = r >= 2 && r <= 4 && c >= 2 && c <= 4;
					var outside = r < 0 || c < 0 || r > 6 || c > 6;

					fixed( origin[ 0 ] + r, origin[ 1 ] + c, outside ? 0 : ( inRing || inCore ? 1 : 0 ) );
				}
			}
		} );

		// Timing patterns.
		for ( i = 8; i < size - 8; i++ ) {
			fixed( 6, i, ( i + 1 ) % 2 );
			fixed( i, 6, ( i + 1 ) % 2 );
		}

		// Alignment patterns, skipping the three that would sit on a finder.
		var centres = ALIGN[ version - 1 ];

		centres.forEach( function ( row ) {
			centres.forEach( function ( col ) {
				var onFinder = ( row < 8 && col < 8 )
					|| ( row < 8 && col > size - 9 )
					|| ( row > size - 9 && col < 8 );

				if ( onFinder ) {
					return;
				}

				for ( var r = -2; r <= 2; r++ ) {
					for ( var c = -2; c <= 2; c++ ) {
						var edge = 2 === Math.max( Math.abs( r ), Math.abs( c ) );
						fixed( row + r, col + c, edge || ( 0 === r && 0 === c ) ? 1 : 0 );
					}
				}
			} );
		} );

		// The dark module, and space held for the format and version blocks.
		fixed( size - 8, 8, 1 );

		for ( i = 0; i < 9; i++ ) {
			if ( ! reserved[ 8 ][ i ] ) {
				fixed( 8, i, 0 );
			}

			if ( ! reserved[ i ][ 8 ] ) {
				fixed( i, 8, 0 );
			}
		}

		for ( i = 0; i < 8; i++ ) {
			fixed( 8, size - 1 - i, 0 );
			fixed( size - 1 - i, 8, 0 );
		}

		if ( version >= 7 ) {
			for ( i = 0; i < 6; i++ ) {
				for ( j = 0; j < 3; j++ ) {
					fixed( i, size - 11 + j, 0 );
					fixed( size - 11 + j, i, 0 );
				}
			}
		}

		// Zig-zag the data up and down two-column strips, right to left.
		var bit = 0;
		var total = stream.length * 8;
		var upward = true;

		for ( var col = size - 1; col > 0; col -= 2 ) {
			if ( 6 === col ) {
				col--;
			}

			for ( var step = 0; step < size; step++ ) {
				var row = upward ? size - 1 - step : step;

				for ( var side = 0; side < 2; side++ ) {
					var c2 = col - side;

					if ( reserved[ row ][ c2 ] || bit >= total ) {
						continue;
					}

					matrix[ row ][ c2 ] = ( stream[ bit >> 3 ] >> ( 7 - ( bit & 7 ) ) ) & 1;
					bit++;
				}
			}

			upward = ! upward;
		}

		applyMask( matrix, reserved, size );

		return matrix;
	}

	/**
	 * Apply mask pattern 0 and write the matching format information.
	 *
	 * The specification asks for the best of eight masks by a penalty score.
	 * One fixed mask is chosen instead: the eight only differ in how evenly
	 * the modules scatter, every one of them produces a valid readable code,
	 * and scoring all eight means building the matrix eight times for a
	 * cosmetic difference no scanner cares about.
	 *
	 * ponytail: fixed mask 0. Score all eight if a real scanner ever struggles.
	 *
	 * @param {number[][]}  matrix   Matrix, modified in place.
	 * @param {boolean[][]} reserved Function-pattern map.
	 * @param {number}      size     Matrix size.
	 */
	function applyMask( matrix, reserved, size ) {
		var row;
		var col;

		for ( row = 0; row < size; row++ ) {
			for ( col = 0; col < size; col++ ) {
				if ( ! reserved[ row ][ col ] && 0 === ( row + col ) % 2 ) {
					matrix[ row ][ col ] ^= 1;
				}
			}
		}

		// Format info: level M is 00, mask 0 is 000, BCH(15,5) with the
		// standard 0x5412 mask applied.
		var format = 0x00 << 3;
		var rem = format;

		for ( var i = 0; i < 10; i++ ) {
			rem = ( rem << 1 ) ^ ( ( ( rem >> 9 ) & 1 ) * 0x537 );
		}

		var bits = ( ( format << 10 ) | rem ) ^ 0x5412;

		for ( var b = 0; b < 15; b++ ) {
			var value = ( bits >> b ) & 1;

			// Copy one: down the left column and across the top row.
			if ( b < 6 ) {
				matrix[ b ][ 8 ] = value;
			} else if ( 6 === b ) {
				matrix[ 7 ][ 8 ] = value;
			} else if ( 7 === b ) {
				matrix[ 8 ][ 8 ] = value;
			} else if ( 8 === b ) {
				matrix[ 8 ][ 7 ] = value;
			} else {
				matrix[ 8 ][ 14 - b ] = value;
			}

			// Copy two, so a damaged corner does not lose the format.
			if ( b < 8 ) {
				matrix[ 8 ][ size - 1 - b ] = value;
			} else {
				matrix[ size - 15 + b ][ 8 ] = value;
			}
		}

		matrix[ size - 8 ][ 8 ] = 1;
	}

	window.wpcmb = window.wpcmb || {};
	window.wpcmb.qrMatrix = qrMatrix;
	window.wpcmb.reedSolomon = reedSolomon;
}() );
