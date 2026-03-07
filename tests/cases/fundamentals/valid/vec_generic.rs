// exit: 0
// stdout: 42

mod alloc;
use crate::alloc::Vec;

fn main() {
    let mut v: Vec<i32> = Vec::new();
    v.push(42);
    let x = v[0];
    println!("{}", x);
    exit(0);
}
