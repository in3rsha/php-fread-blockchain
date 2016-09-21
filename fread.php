<?php

// Handy Functions
function swapEndian($hex) { return implode('', array_reverse(str_split($hex, 2))); }
function blk00000($i) { return 'blk'.str_pad($i, 5, '0', STR_PAD_LEFT).'.dat'; }
function varInt($transactions) { // Calculates the full variable integer and returns it
	$varint = substr($transactions, 0, 2);
	
	if     ($varint == 'fd') { $value = substr($transactions, 2, 4);  $full = $varint.$value; $len = 6;}
	elseif ($varint == 'fe') { $value = substr($transactions, 2, 8);  $full = $varint.$value; $len = 10;}
	elseif ($varint == 'ff') { $value = substr($transactions, 2, 16); $full = $varint.$value; $len = 18;}
	else 					 { $value = $varint; $full = $varint; $len = 2; }
	
	return array($full, $value, $len);	
}

// Settings
$folder = '/home/user/.bitcoin/blocks';
$filenumber = 0; // which blk.dat file to start with


// -------------------
// READ THE BLOCKCHAIN
//--------------------
while(true) { // Keep trying to read files forever
	
	$file = blk00000($filenumber); // format file number (e.g. blk00420.dat instead of blk420.dat)
	$fp = fopen("$folder/$file", 'rb'); echo "Reading $file...\n"; sleep(1);
		
	$b = 1; // for counting the blocks in each file
	while(true) { // Read through a blk*.dat file

		// 1. Read message header
		$header = bin2hex(fread($fp, 8)); // first 8 bytes gives you the header
		
		// If we have reached the end of the file
		if($header == NULL) {
			
			// ...and there is a successive file
			$nextfile = blk00000($filenumber+1);
			if (file_exists("$folder/$nextfile")) {
				echo "There is a file $nextfile.\n";
				sleep(1);
				$filenumber++; // Set the file number to the next one
				break;	// ... Restart main loop (opens next file)
			}
			
			else {
				echo "Waiting for new file.\n";
				sleep(1); // just wait a second and restart loop (wait for new file to be made
				break;
			}
			
		}
		
		// If the header has read lots of zeros, which means it's not the end of the file but there is more data to come
		if ($header == '0000000000000000') { 
			echo "  re-reading the file...$pointer\n";
			sleep(1);
			fseek($fp, $pointer); // rewind pointer to end of last block (before we read the 8 bytes for the header) 
			continue; // go back to start of loop and read message header again
		}
		
		
		// 1. Parse message header
		$magicbytes =	substr($header, 0, 8);
		$blocksize =	hexdec(swapEndian(substr($header, 8, 8)));

		// 2. Read the size of this block
		$block = bin2hex(fread($fp, intval($blocksize)));
		$pointer = ftell($fp);

		// Block Header
		$version =		substr($block, 0, 8);
		$prevblock =	substr($block, 8, 64);
		$merkleroot =	substr($block, 72, 64);
		$timestamp =	substr($block, 136, 8);
		$bits =			substr($block, 144, 8);
		$nonce =		substr($block, 152, 8); // 80 bytes total

		// i. Work out this block's hash
		$blockheader = $version.$prevblock.$merkleroot.$timestamp.$bits.$nonce;
		$blockhash = swapEndian(hash('sha256', hash('sha256', hex2bin($blockheader), true)));

		// 3. Save Block
		//file_put_contents("blockdb/$blockhash.txt", $block);
		echo "  $b: $blockhash [$blocksize bytes] ($pointer)\n";
		
		
		// TRANSACTIONS
		// 0. Number of upcoming transactions (varint)
		$varint = substr($block, 160); list($full, $value, $len) = varInt($varint);
		$numtxs = hexdec(swapEndian($value));
		echo "      $numtxs\n";
		
		$transactions = substr($block, 160+$len);
		
		// READTX
		// 1. Read each transaction in this string of transactions
		$p = 0; // pointer
		while (isset($transactions[$p])) { // continue until end of string
			
			// Start Storing
			$txbuffer = ''; // clear the tx buffer, ready to start storing a tx data
			
			// version (4 bytes)
			$txbuffer .= substr($transactions, $p, 8); $p+=8;
			
			// inputs
			list($full, $value, $len) = varInt(substr($transactions, $p));
			$txbuffer .= $full; $p+=$len; // inputcount (varint)
			$count = hexdec(swapEndian($value));

			for ($i=1; $i<=$count; $i++) {
				$txbuffer .= substr($transactions, $p, 64); $p+=64; // txid (32 bytes)
				$txbuffer .= substr($transactions, $p, 8); $p+=8; // vout (4 bytes)
				list($full, $value, $len) = varInt(substr($transactions, $p)); // (varint)
				$txbuffer .= $full; $p+=$len; // signaturesize
				$size = hexdec(swapEndian($value))*2; // number of chars
				$txbuffer .= substr($transactions, $p, $size); $p += $size; // signature
				$txbuffer .= substr($transactions, $p, 8); $p+=8; // sequence
			}

			// outputs
			list($full, $value, $len) = varInt(substr($transactions, $p));
			$txbuffer .= $full; $p+=$len; // outputcount (varint)
			$count = hexdec(swapEndian($value));

			for ($i=1; $i<=$count; $i++) {
				$txbuffer .= substr($transactions, $p, 16); $p+=16; // value (8 bytes)
				list($full, $value, $len) = varInt(substr($transactions, $p)); // (varint)
				$txbuffer .= $full; $p+=$len; // lockingscriptsize
				$size = hexdec(swapEndian($value))*2; //  number of chars
				$txbuffer .= substr($transactions, $p, $size); $p += $size; // lockingscript
			}

			// locktime (4 bytes)
			$txbuffer .= substr($transactions, $p, 8); $p+=8;
			
			
			// Echo the txid
			echo "        ".swapEndian(hash('sha256', hash('sha256', hex2bin($txbuffer), true)))."\n";
			// move on to next tx...
		}
	
		$b++; // update block count for this blk.dat file
		
	}
	
}





