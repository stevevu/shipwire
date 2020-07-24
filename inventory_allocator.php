<?php

const HEADER = 'Header';
const LINES = 'Lines';
const PRODUCT = 'Product';
const QUANTITY = 'Quantity';
const DEMANDS_FOR_LINE = 'demands_for_line';
const ALLOCATIONS_FOR_LINE = 'allocations_for_line';
const BACKORDERS_FOR_LINE = 'backorders_for_line';

/**
 * Main driver
 * 
 * Print out the demand, allocation, and backorder for each
 * order
 * 
 * @param string[] $orders an array of JSON strings, each representing an order
 * 
 * @return boolean 
 */
function main($orders) {
  $inventory = get_initial_inventory();
  $product_ids = array_keys($inventory);

  $headers_found = [];
  $lines_stats = [];
  
  foreach ($orders as $order_json) {
    list (
      $demands_for_line, 
      $allocations_for_line, 
      $backorders_for_line
    ) = reset_line_stats($product_ids);
    
    if ($order = json_decode($order_json, true)) {
      if (is_valid_order($order)) {
        $header = $order[HEADER];

        // check that header is unique
        if (!is_valid_header($header, $headers_found)) {
          continue;
        }
        $headers_found[] = $header;
        foreach ($order[LINES] as $line) {
          $product_id = $line[PRODUCT];
          $quantity = $line[QUANTITY];
          if (!array_key_exists($product_id, $inventory)) {
            error_log("ERROR: invalid product ordered: ${product_id}");
          }
          else {
            $demands_for_line[$product_id] = $quantity;
            $allocations_for_line[$product_id] = $inventory[$product_id] >= $quantity ? $quantity : $inventory[$product_id];
            $backorders_for_line[$product_id] = $quantity - $allocations_for_line[$product_id];
            $inventory[$product_id] = $inventory[$product_id] - $allocations_for_line[$product_id] > 0 ? 
                                      $inventory[$product_id] - $allocations_for_line[$product_id] : 0;
          }
        }

        $lines_stats[] = [
          HEADER => $header,
          DEMANDS_FOR_LINE => join(',', $demands_for_line),
          ALLOCATIONS_FOR_LINE => join(',', $allocations_for_line),
          BACKORDERS_FOR_LINE => join(',', $backorders_for_line) 
        ];

        // check inventory total; if == 0, break out and print stats
        if (inventory_total($inventory) === 0) {
          print_lines_stats($lines_stats);
          break;
        }
      }
    }
    else {
      error_log("ERROR: could not parse order json: ${order_json}");
    }
  }
  return true;
}

/**
 * Get the intial inventory
 * 
 * @return array an array of each product and its intial inventory
 */
function get_initial_inventory() {
  return [
    'A' => 2,
    'B' => 3,
    'C' => 1,
    'D' => 0,
    'E' => 0
  ];
}

/**
 * Get the orders
 * 
 * @return string[] an array of orders, each represented as a JSON string
 */
function get_orders() {
  return [
    '{"Header": 1, "Lines": [{"Product": "A", "Quantity": "1"},{"Product": "C", "Quantity": "1"}]}',
    '{"Header": 2, "Lines": [{"Product": "E", "Quantity": "5"}]}',
    '{"Header": 3, "Lines": [{"Product": "D", "Quantity": "4"}]}',
    '{"Header": 4, "Lines": [{"Product": "A", "Quantity": "1"}, {"Product": "C", "Quantity": "1"}]}',
    '{"Header": 5, "Lines": [{"Product": "B", "Quantity": "3"}]}',
    '{"Header": 6, "Lines": [{"Product": "D", "Quantity": "4"}]}'
  ];
}

/**
 * Determine if an order line is valid
 * 
 * @param array $order an order
 */
function is_valid_order($order) {
  $is_valid_order = true;

  // check if Header is present
  if (!array_key_exists(HEADER, $order)) {
    $is_valid_order = false;
    error_log("ERROR: Invalid order found with no Header:\n" . var_export($order, true));
  }

  // check if Lines is present
  else if (!array_key_exists(LINES, $order)) {
    $is_valid_order = false;
    error_log("ERROR: Invalid order found with no Lines:\n" . var_export($order, true));
  }

  // check if Lines is an array
  else if (!is_array($order[LINES])) {
    $is_valid_order = false;
    error_log("ERROR: Invalid order found with non-array Lines:\n" . var_export($order, true));
  }

  // check if there is at least one Line item
  else if (!count($order[LINES])) {
    $is_valid_order = false;
    error_log("ERROR: Invalid order found with no Line items:\n" . var_export($order, true));
  }

  // check if there is at least one total demand
  else if (line_demand_total($order[LINES]) === 0) {
    $is_valid_order = false;
    error_log("ERROR: Invalid order found with zero total demand:\n" . var_export($order, true));
  }

  return $is_valid_order;
}

/**
 * Determine the total quantity for an order
 * 
 * @param array array of order data
 * 
 * @return total quantity in order
 */
function line_demand_total($line_orders) {
  $line_demand_total = array_reduce(
    $line_orders,
    function ($sum, $line) {
      return $sum + intval($line[QUANTITY]);
    },
    0);
  return $line_demand_total;
}

/**
 * Check if an order id (header) was previously encountered
 * 
 * @param string $header the order ID
 * @param string[] $headers_found an array containing the order ids previously found
 * 
 * @return boolean true is order id is valid; false, otherwise
 */
function is_valid_header ($header, $headers_found) {
  $is_valid_header = true;
  if (in_array($header, $headers_found)) {
    $is_valid_header = false;
    error_log("ERROR: order found with existing header: ${header}");    
  }
  return $is_valid_header;
}

/**
 * Reset all quantities of the demand, allocation, and backorder of each product id to zero
 * 
 * @param string[] @product_ids an array of unique product_ids generated from the initial inventory
 * 
 * @return array an array of the demand, allocation, and backorder quantities of each product id set to zero
 */
function reset_line_stats($product_ids) {
  $demands_for_line = [];
  $allocations_for_line = [];
  $backorders_for_line = [];
  foreach ($product_ids as $product_id) {
    $demands_for_line[$product_id] = 0;
    $allocations_for_line[$product_id] = 0;
    $backorders_for_line[$product_id]  = 0;
  }
  return [$demands_for_line, $allocations_for_line, $backorders_for_line];
}

/**
 * Calculate the inventory total
 * 
 * @param array[string]int $inventory the current inventory data
 * 
 * @return int the inventory total
 */
function inventory_total($inventory) {
  $inventory_total = 0;
  foreach ($inventory as $product_id => $quantity) {
    $inventory_total += $quantity;
  }
  return $inventory_total;
}

/**
 * print the order id, demands, allocations, and backorders for each order
 * 
 * @param array $line_stats an array of line order details
 * 
 * @return bool
 */
function print_lines_stats($lines_stats) {
  foreach ($lines_stats as $line_stat) {
    print "{$line_stat[HEADER]}:{$line_stat[DEMANDS_FOR_LINE]}::{$line_stat[ALLOCATIONS_FOR_LINE]}::{$line_stat[BACKORDERS_FOR_LINE]}\n";
  }
  return true;
}

main(get_orders());
