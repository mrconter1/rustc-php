// exit: 0
// stdout: 42

mod alloc;
use crate::alloc::VecI32;

fn main() {
    let mut v = VecI32::new();
    v.push(42);
    let x = v[0];
    println!("{}", x);
    exit(0);
}
