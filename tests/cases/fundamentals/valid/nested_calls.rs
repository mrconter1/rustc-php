// stdout: 25
fn double(x: i32) -> i32 {
    return x * 2;
}

fn add_one(x: i32) -> i32 {
    return x + 1;
}

fn main() {
    println!("{}", add_one(double(double(add_one(5)))));
}
