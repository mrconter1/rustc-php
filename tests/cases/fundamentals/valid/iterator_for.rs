// exit: 10
mod alloc;
use crate::alloc::Vec;

fn main() {
    let mut v: Vec<i32> = Vec::new();
    v.push(1);
    v.push(2);
    v.push(3);
    v.push(4);
    let mut sum = 0;
    for x in v.iter() {
        sum = sum + x;
    }
    exit(sum);
}
