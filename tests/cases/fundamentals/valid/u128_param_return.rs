// stdout: 2
fn identity(x: u128) -> u128 {
    x
}

fn main() {
    let a: u128 = 1;
    let b = identity(a);
    let c = identity(1);
    println!("{}", b + c);
}
