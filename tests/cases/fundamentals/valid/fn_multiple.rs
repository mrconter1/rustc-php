// stdout: 25
fn square(x: i32) -> i32 {
    return x * x;
}

fn double(x: i32) -> i32 {
    return x + x;
}

fn main() {
    let a: i32 = square(3);
    let b: i32 = double(8);
    println!("{}", a + b);
}
