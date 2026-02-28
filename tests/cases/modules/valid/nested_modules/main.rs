// exit: 0
// stdout: 42

mod math;

use crate::math::algebra::solve;

fn main() {
    let result = solve(6, 7);
    println!("{}", result);
}
