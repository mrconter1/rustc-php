// stdout: 42
fn print_val(x: &i32) {
    println!("{}", x);
}

fn main() {
    let n: i32 = 42;
    print_val(&n);
}
