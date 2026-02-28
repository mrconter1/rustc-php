// exit: 0
// stdout: 30

mod math;

use crate::math::add;

fn main() {
    let result = add(10, 20);
    println!("{}", result);
}
